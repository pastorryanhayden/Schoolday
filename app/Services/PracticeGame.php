<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\PracticeAttempt;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PracticeGame
{
    public static function access(User $actor, Assignment $assignment, bool $write = false): void
    {
        abort_unless($assignment->isPractice(), 404);
        abort_unless(($actor->role === 'parent' && ! $write) || ($actor->role === 'student' && $assignment->student_id === $actor->id), 403);
    }

    public static function round(Assignment $assignment): int
    {
        return $assignment->revision + (in_array($assignment->status, ['submitted', 'approved']) ? 0 : 1);
    }

    private static function milliseconds(Assignment $assignment, int $revision): int
    {
        return (int) PracticeAttempt::where('assignment_id', $assignment->id)->where('revision', $revision)->sum('credited_ms');
    }

    public static function act(User $actor, Assignment $assignment, array $data): array
    {
        self::access($actor, $assignment, true);
        $data = Validator::make($data, ['action' => 'required|in:start,next,answer,pause,help,cover,listen', 'version' => 'required|integer|min:0', 'attempt_id' => 'required_if:action,answer,help,cover,listen|integer|min:1', 'answer' => $assignment->activity_type === 'spelling' ? 'required_if:action,answer|string|max:80' : 'required_if:action,answer|integer|min:0|max:999', 'response_ms' => 'required_if:action,answer|integer|min:100|max:120000', 'feedback_ms' => 'nullable|integer|min:0|max:5000'])->validate();
        if ($data['action'] === 'answer') {
            $answered = PracticeAttempt::where('assignment_id', $assignment->id)->where('student_id', $actor->id)->whereNotNull('answered_at')->find($data['attempt_id']);
            if ($answered) {
                abort_unless(($assignment->activity_type === 'spelling' ? $answered->answer_text === self::normalizeWord($data['answer']) : $answered->answer === (int) $data['answer']) && $answered->response_ms === (int) $data['response_ms'], 409, 'That question has already been answered.');

                return self::payload($actor, $assignment->fresh(), null, $answered);
            }
        }

        return DB::transaction(function () use ($actor, $assignment, $data): array {
            $assignment = $assignment->fresh();
            if ($data['action'] === 'start') {
                DailyRoutine::transition($actor, 'activity-start', (int) $data['version'], $assignment->id);
                $student = $actor->fresh();
            } else {
                $student = DailyRoutine::lock($actor, (int) $data['version']);
            }
            $day = DailyRoutine::openDay($student);
            $session = DailyRoutine::active($day);
            abort_unless($session && $session->kind === 'work' && $session->assignment_id === $assignment->id, 409, 'This game is not your current activity. Start or resume practice first.');
            abort_unless(in_array($assignment->status, ['not_started', 'in_progress', 'needs_changes']), 409, 'This practice has already been submitted.');
            $revision = $assignment->revision + 1;
            if ($data['action'] === 'pause') {
                self::interrupt($assignment);
                DailyRoutine::transition($student, 'activity-stop', $student->routine_version);

                return self::payload($student->fresh(), $assignment);
            }
            if (in_array($data['action'], ['answer', 'help', 'cover', 'listen'])) {
                $attempt = PracticeAttempt::where('student_id', $student->id)->where('assignment_id', $assignment->id)->where('work_session_id', $session->id)->where('revision', $revision)->whereNull('answered_at')->where('interrupted', false)->findOrFail($data['attempt_id']);
                $age = max(0, (int) $attempt->issued_at->diffInMilliseconds(now()));
                abort_if($age > 120000 || ($data['action'] === 'answer' && (int) $data['response_ms'] > $age + 2000), 409, 'This question was interrupted or left open too long. Resume for a fresh question.');
                if (in_array($data['action'], ['cover', 'listen'])) {
                    abort_unless($assignment->activity_type === 'spelling', 422);
                    if ($data['action'] === 'cover') {
                        $attempt->update(['covered_at' => now()]);
                    } else {
                        abort_if($attempt->mode === 'study', 422);
                        $attempt->increment('audio_plays');
                    }

                    return self::payload($student, $assignment, $attempt->fresh()) + ['speech' => $data['action'] === 'listen' ? $attempt->word : null];
                }
                if ($data['action'] === 'help') {
                    if (! $attempt->assisted_at) {
                        $attempt->update(['assisted_at' => now()]);
                        School::record($student, 'practice_help', $assignment->activity_type === 'spelling' ? 'Looked again at spelling: '.$attempt->word : 'Used multiplication chart for '.$attempt->left.' × '.$attempt->right, $assignment);
                    }

                    if ($assignment->activity_type === 'spelling') {
                        $attempt->update(['covered_at' => null]);
                    }

                    return self::payload($student, $assignment, $attempt);
                }
                if ($assignment->activity_type === 'spelling') {
                    abort_unless($attempt->covered_at || ($attempt->mode !== 'study' && $attempt->audio_plays > 0 && ! $attempt->assisted_at), 409, 'Hear the word or cover it before answering.');
                    $text = self::normalizeWord($data['answer']);
                    abort_if($attempt->mode === 'choose' && ! in_array($text, $attempt->choices, true), 422, 'Choose one of the displayed spellings.');
                    $correct = $text === $attempt->word;
                } else {
                    $correct = (int) $data['answer'] === $attempt->left * $attempt->right;
                }
                $credited = min(60000, (int) $data['response_ms']);
                $attempt->update(['answer' => $assignment->activity_type === 'spelling' ? null : (int) $data['answer'], 'answer_text' => $text ?? null, 'correct' => $correct, 'response_ms' => (int) $data['response_ms'], 'credited_ms' => $credited, 'answered_at' => now()]);
                $session->increment('practice_ms', $credited);
                self::completeIfReady($student, $assignment, $revision);

                return self::payload($student->fresh(), $assignment->fresh(), null, $attempt->fresh());
            }
            if ($data['action'] === 'next' && isset($data['attempt_id'], $data['feedback_ms'])) {
                $previous = PracticeAttempt::where('student_id', $student->id)->where('assignment_id', $assignment->id)->where('work_session_id', $session->id)->where('revision', $revision)->whereNotNull('answered_at')->findOrFail($data['attempt_id']);
                if (! $previous->feedback_recorded) {
                    $age = max(0, (int) $previous->answered_at->diffInMilliseconds(now()));
                    $feedback = min((int) $data['feedback_ms'], $age + 2000);
                    $previous->update(['feedback_ms' => $feedback, 'feedback_recorded' => true, 'credited_ms' => $previous->credited_ms + $feedback]);
                    $session->increment('practice_ms', $feedback);
                }
                if (self::completeIfReady($student, $assignment, $revision)) {
                    return self::payload($student->fresh(), $assignment->fresh());
                }
            }
            abort_if(self::targetReached($assignment, $revision), 409, 'The practice target is already complete. Refresh to see the results.');
            self::interrupt($assignment);
            $prompt = $assignment->activity_type === 'spelling' ? self::chooseWord($student, $assignment, $revision) : array_combine(['left', 'right'], self::chooseFact($student, $assignment));
            $question = PracticeAttempt::create(['student_id' => $student->id, 'assignment_id' => $assignment->id, 'work_session_id' => $session->id, 'revision' => $revision, 'issued_at' => now()] + $prompt);

            return self::payload($student, $assignment, $question);
        }, 3);
    }

    private static function targetReached(Assignment $assignment, int $revision): bool
    {
        if ($assignment->question_target !== null) {
            return PracticeAttempt::where('assignment_id', $assignment->id)->where('revision', $revision)->whereNotNull('answered_at')->count() >= $assignment->question_target;
        }

        return self::milliseconds($assignment, $revision) >= $assignment->minutes * 60000;
    }

    private static function completeIfReady(User $student, Assignment $assignment, int $revision): bool
    {
        if (! self::targetReached($assignment, $revision)) {
            return false;
        }
        self::interrupt($assignment);
        DailyRoutine::submitted($student, $assignment);
        $assignment->update(['status' => 'submitted', 'revision' => $revision, 'submitted_at' => now()]);
        School::record($student, 'practice_complete', 'Completed '.$assignment->activity_type.' practice (round '.$revision.'); ready for Dad’s review', $assignment);

        return true;
    }

    private static function interrupt(Assignment $assignment): void
    {
        PracticeAttempt::where('assignment_id', $assignment->id)->whereNull('answered_at')->where('interrupted', false)->update(['interrupted' => true]);
    }

    private static function normalizeWord(string $word): string
    {
        return mb_strtolower(trim(str_replace('’', "'", $word)));
    }

    private static function chooseWord(User $student, Assignment $assignment, int $revision): array
    {
        $history = PracticeAttempt::where('student_id', $student->id)->whereIn('word', $assignment->spelling_words)->where(fn ($query) => $query->whereNotNull('answered_at')->orWhereNotNull('assisted_at'))->orderByDesc('id')->limit(2000)->get();
        $round = PracticeAttempt::where('assignment_id', $assignment->id)->where('revision', $revision)->whereNotNull('answered_at')->get();
        $offset = count($assignment->spelling_words) % 3 === 0 ? intdiv($round->count(), count($assignment->spelling_words)) : 0;
        $mode = ['study', 'listen', 'choose'][($round->count() + $offset) % 3];
        $groups = $history->groupBy('word');
        $counts = $round->countBy('word');
        $minimum = collect($assignment->spelling_words)->min(fn ($word) => $counts->get($word, 0));
        $candidates = collect($assignment->spelling_words)->filter(fn ($word) => $counts->get($word, 0) === $minimum)->sortByDesc(function ($word) use ($groups) {
            return $groups->get($word, collect())->take(5)->filter(fn ($attempt) => $attempt->correct === false || $attempt->assisted_at)->count();
        });
        $word = $candidates->first();
        $choices = null;
        if ($mode === 'choose') {
            $last = mb_substr($word, -1);
            $middle = max(0, (int) floor(mb_strlen($word) / 2) - 1);
            $swapped = mb_substr($word, 0, $middle).mb_substr($word, $middle + 1, 1).mb_substr($word, $middle, 1).mb_substr($word, $middle + 2);
            $shortened = mb_substr($word, 0, $middle).mb_substr($word, $middle + 1);
            $choices = [$word, $swapped === $word ? $word.$last : $swapped, $shortened ?: $word.$last.$last];
            // Distinct distractors are checked against the weekly list, which may contain related words.
            foreach ([1, 2] as $index) {
                while (in_array($choices[$index], $assignment->spelling_words, true) || ($index === 2 && $choices[2] === $choices[1])) {
                    $choices[$index] .= $last;
                }
            }
            shuffle($choices);
        }

        return ['word' => $word, 'mode' => $mode, 'choices' => $choices];
    }

    public static function report(Assignment $assignment): array
    {
        $summary = self::summary($assignment);
        if ($assignment->activity_type !== 'spelling') {
            return $summary;
        }
        $attempts = PracticeAttempt::where('assignment_id', $assignment->id)->orderByDesc('id')->get();
        $summary['words'] = $attempts->groupBy('word')->map(fn ($group, $word) => ['word' => $word, 'needs_drill' => $group->take(5)->contains(fn ($attempt) => $attempt->correct === false || $attempt->assisted_at !== null), 'replays' => $group->sum(fn ($attempt) => max(0, $attempt->audio_plays - 1))] + self::summarize($group))->values()->all();
        $summary['modes'] = $attempts->groupBy('mode')->map(fn ($group, $mode) => ['mode' => $mode] + self::summarize($group))->values()->all();

        return $summary;
    }

    private static function factKey(int $left, int $right): string
    {
        return min($left, $right).'x'.max($left, $right);
    }

    private static function chooseFact(User $student, Assignment $assignment): array
    {
        $history = PracticeAttempt::where('student_id', $student->id)->whereNotNull('left')->where(fn ($query) => $query->whereNotNull('answered_at')->orWhereNotNull('assisted_at'))->orderByDesc('id')->limit(2000)->get();
        $groups = $history->groupBy(fn ($attempt) => self::factKey($attempt->left, $attempt->right));
        $recent = $history->take(2)->map(fn ($attempt) => self::factKey($attempt->left, $attempt->right))->all();
        $pool = [];
        for ($left = $assignment->fact_min; $left <= $assignment->fact_max; $left++) {
            for ($right = $left; $right <= $assignment->fact_max; $right++) {
                $key = self::factKey($left, $right);
                $attempts = $groups->get($key, collect());
                $latest = $attempts->first();
                $speed = $attempts->where('correct', true)->whereNull('assisted_at')->take(10)->median('response_ms');
                $needsDrill = $attempts->take(5)->contains(fn ($attempt) => $attempt->assisted_at !== null || $attempt->correct === false);
                $weight = ! $latest ? 5 : ($needsDrill || ! $latest->correct ? 12 : max(1, min(10, (int) ceil(($speed ?? 3000) / 1500))));
                $pool[] = compact('left', 'right', 'key', 'weight');
            }
        }
        $fresh = array_values(array_filter($pool, fn ($fact) => ! in_array($fact['key'], $recent)));
        $pool = $fresh ?: $pool;
        $pick = random_int(1, array_sum(array_column($pool, 'weight')));
        foreach ($pool as $fact) {
            $pick -= $fact['weight'];
            if ($pick <= 0) {
                return random_int(0, 1) ? [$fact['left'], $fact['right']] : [$fact['right'], $fact['left']];
            }
        }
        throw new \LogicException('No multiplication facts were available.');
    }

    private static function payload(User $student, Assignment $assignment, ?PracticeAttempt $question = null, ?PracticeAttempt $answer = null): array
    {
        $state = DailyRoutine::state($student);

        $spelling = $assignment->activity_type === 'spelling';
        $prompt = $question?->only(['id', 'left', 'right']);
        if ($question && $spelling) {
            $visible = ! $question->covered_at && ($question->mode === 'study' || $question->assisted_at);
            $prompt = ['id' => $question->id, 'mode' => $question->mode, 'word' => $visible ? $question->word : null, 'covered' => $question->covered_at !== null, 'choices' => $question->choices, 'audio_plays' => $question->audio_plays, 'can_answer' => ! $visible && ($question->covered_at !== null || $question->audio_plays > 0)];
        }
        $result = $answer ? ['attempt_id' => $answer->id, 'correct' => $answer->correct, 'assisted' => $answer->assisted_at !== null, 'left' => $answer->left, 'right' => $answer->right, 'answer' => $spelling ? $answer->word : $answer->left * $answer->right] : null;
        if ($result && $spelling) {
            $result += ['typed' => $answer->answer_text, 'mode' => $answer->mode];
        }

        return ['state' => $state, 'running' => ($state['current']['kind'] ?? null) === 'work' && ($state['current']['assignment_id'] ?? null) === $assignment->id && (! $answer || ($state['current']['id'] ?? null) === $answer->work_session_id), 'question' => $prompt, 'result' => $result, 'complete' => in_array($assignment->status, ['submitted', 'approved']), 'summary' => self::summary($assignment)];
    }

    private static function summarize(Collection $attempts): array
    {
        $answered = $attempts->whereNotNull('answered_at');
        $correct = $answered->where('correct', true);
        $independent = $correct->whereNull('assisted_at');

        return ['attempts' => $answered->count(), 'correct' => $correct->count(), 'accuracy' => $answered->isEmpty() ? null : round(100 * $correct->count() / $answered->count()), 'practice_ms' => (int) $answered->sum('credited_ms'), 'median_correct_ms' => $independent->isEmpty() ? null : (int) $independent->median('response_ms'), 'help_uses' => $attempts->whereNotNull('assisted_at')->count()];
    }

    public static function summary(Assignment $assignment): array
    {
        abort_unless($assignment->isPractice(), 404);
        $attempts = PracticeAttempt::where('assignment_id', $assignment->id)->orderByDesc('id')->get();
        $revision = self::round($assignment);
        $rounds = $attempts->groupBy('revision')->map(fn ($group, $revision) => ['revision' => (int) $revision] + self::summarize($group))->values()->all();

        return ['revision' => $revision, 'question_target' => $assignment->question_target, 'target_ms' => ($assignment->minutes ?? 5) * 60000, 'round' => self::summarize($attempts->where('revision', $revision)), 'rounds' => $rounds];
    }

    public static function facts(User $student): array
    {
        $attempts = PracticeAttempt::where('student_id', $student->id)->whereNotNull('left')->where(fn ($query) => $query->whereNotNull('answered_at')->orWhereNotNull('assisted_at'))->orderByDesc('id')->get();
        $facts = $attempts->groupBy(fn ($attempt) => self::factKey($attempt->left, $attempt->right))->map(function ($group, $key): array {
            $latest = $group->first();
            $recent = $group->where('correct', true)->whereNull('assisted_at')->take(10);
            $needsDrill = $group->take(5)->contains(fn ($attempt) => $attempt->assisted_at !== null || $attempt->correct === false);
            $median = $recent->isEmpty() ? null : (int) $recent->median('response_ms');

            return ['fact' => str_replace('x', ' × ', $key), 'recent_correct_median_ms' => $median, 'latest_correct' => $latest->correct, 'needs_drill' => $needsDrill || $median === null || $median > 3000, 'quick_recently' => $latest->correct && ! $latest->assisted_at && $median !== null && $median <= 3000] + self::summarize($group);
        })->sortBy([['needs_drill', 'desc'], ['help_uses', 'desc'], ['accuracy', 'asc'], ['recent_correct_median_ms', 'desc']])->values()->all();

        return ['totals' => self::summarize($attempts), 'facts' => $facts, 'quick_recall_target_ms' => 3000];
    }
}
