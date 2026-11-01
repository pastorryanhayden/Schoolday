<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Book;
use App\Models\Message;
use App\Models\Recurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ParentActions
{
    public const ASSIGNMENT_FIELDS = ['activity_type', 'fact_min', 'fact_max', 'question_target', 'spelling_words', 'book_id', 'date', 'subject', 'title', 'instructions', 'reference', 'minutes', 'requires_photo', 'position'];

    public static function authorize(User $parent): void
    {
        abort_unless($parent->role === 'parent', 403, 'A parent account is required.');
    }

    public static function student(): User
    {
        return User::where('role', 'student')->firstOrFail();
    }

    public static function assignment(User $parent, int $id): Assignment
    {
        self::authorize($parent);

        return Assignment::where('student_id', self::student()->id)->findOrFail($id);
    }

    public static function validateAssignment(array $data): array
    {
        if (is_string($data['spelling_words'] ?? null)) {
            $data['spelling_words'] = preg_split('/\R/', $data['spelling_words'], -1, PREG_SPLIT_NO_EMPTY);
        }
        if (is_array($data['spelling_words'] ?? null)) {
            $data['spelling_words'] = array_values(array_unique(array_map(fn ($word) => is_string($word) ? mb_strtolower(trim(str_replace('’', "'", $word))) : $word, $data['spelling_words']), SORT_REGULAR));
        }
        $data += ['activity_type' => 'assignment', 'fact_min' => 1, 'fact_max' => 12];
        $rules = [
            'assignment' => 'required|array:'.implode(',', self::ASSIGNMENT_FIELDS),
            'assignment.activity_type' => 'required|in:assignment,multiplication,spelling',
            'assignment.spelling_words' => 'nullable|array|list|max:50',
            'assignment.spelling_words.*' => ['required', 'string', 'max:40', "regex:/^[a-z]+(?:['-][a-z]+)*$/"],
            'assignment.question_target' => 'nullable|integer|min:1|max:100',
            'assignment.fact_min' => 'required|integer|min:1|max:12',
            'assignment.fact_max' => 'required|integer|min:1|max:12|gte:assignment.fact_min',
            'assignment.book_id' => ['nullable', 'integer', Rule::exists('books', 'id')->where('student_id', self::student()->id)],
            'assignment.date' => 'required|date_format:Y-m-d',
            'assignment.subject' => 'required|string|max:60',
            'assignment.title' => 'required|string|max:160',
            'assignment.instructions' => 'required|string|max:8000',
            'assignment.reference' => 'nullable|string|max:160',
            'assignment.minutes' => 'nullable|integer|min:1|max:480',
            'assignment.requires_photo' => 'required|boolean',
            'assignment.position' => 'required|integer|min:0|max:999',
        ];
        if (in_array($data['activity_type'], ['multiplication', 'spelling'])) {
            $rules['assignment.minutes'] = 'required|integer|min:1|max:15';
            $rules['assignment.requires_photo'] = 'required|boolean|declined';
        }

        if ($data['activity_type'] === 'spelling') {
            $rules['assignment.spelling_words'] = 'required|array|list|min:1|max:50';
        }

        return Validator::make(['assignment' => $data], $rules)->validate()['assignment'];
    }

    public static function create(User $parent, array $data): Assignment
    {
        self::authorize($parent);
        $student = self::student();
        $data = self::assignmentDefaults($data);
        $data += ['requires_photo' => false, 'position' => 0];
        $data = self::validateAssignment($data);

        return DB::transaction(function () use ($parent, $student, $data): Assignment {
            $assignment = Assignment::create($data + ['student_id' => $student->id]);
            School::record($parent, 'assignment_created', 'Created: '.$assignment->title, $assignment);

            return $assignment->fresh();
        });
    }

    public static function update(User $parent, int $id, array $patch): Assignment
    {
        $assignment = self::assignment($parent, $id);
        abort_if($patch === [], 422, 'Supply at least one assignment field to update.');

        return DB::transaction(function () use ($parent, $assignment, $patch): Assignment {
            $assignment = Assignment::lockForUpdate()->findOrFail($assignment->id);
            abort_unless($assignment->status === 'not_started' && ! $assignment->started_at, 409, 'Only unstarted work can be edited.');
            $data = $assignment->only(self::ASSIGNMENT_FIELDS);
            $data['date'] = $assignment->date->toDateString();
            $data = self::validateAssignment(array_replace($data, $patch));
            $assignment->update($data);
            School::record($parent, 'assignment_updated', 'Updated: '.$assignment->title, $assignment);

            return $assignment->fresh();
        });
    }

    public static function review(User $parent, int $id, string $decision, ?string $feedback, ?int $revision = null): Assignment
    {
        $assignment = self::assignment($parent, $id);
        $data = Validator::make(['decision' => $decision, 'feedback' => $feedback === null ? null : trim($feedback)], [
            'decision' => 'required|in:approved,needs_changes',
            'feedback' => 'nullable|required_if:decision,needs_changes|string|max:4000',
        ])->validate();

        return DB::transaction(function () use ($parent, $assignment, $data, $revision): Assignment {
            $assignment = Assignment::lockForUpdate()->findOrFail($assignment->id);
            abort_unless($assignment->status === 'submitted', 409, 'Only submitted work can be reviewed.');
            abort_if($revision !== null && $assignment->revision !== $revision, 409, 'The submission revision has changed. Inspect the current work before reviewing.');
            $assignment->update(['status' => $data['decision'], 'feedback' => $data['feedback'], 'approved_at' => $data['decision'] === 'approved' ? now() : null]);
            School::record($parent, 'review', ($data['decision'] === 'approved' ? 'Approved: ' : 'Changes requested: ').$assignment->title.' — '.($data['feedback'] ?? ''), $assignment);
            Message::create(['sender_id' => $parent->id, 'student_id' => $assignment->student_id, 'assignment_id' => $assignment->id, 'body' => ($data['decision'] === 'approved' ? 'Approved! ' : 'Please make corrections. ').($data['feedback'] ?? 'Nice work.')]);

            return $assignment->fresh();
        });
    }

    public static function message(User $parent, string $body, ?int $assignmentId = null): Message
    {
        self::authorize($parent);
        $student = self::student();
        $assignment = $assignmentId === null ? null : self::assignment($parent, $assignmentId);
        $data = Validator::make(['body' => trim($body)], ['body' => 'required|string|max:4000'])->validate();

        return DB::transaction(function () use ($parent, $student, $assignment, $data): Message {
            $message = Message::create($data + ['sender_id' => $parent->id, 'student_id' => $student->id, 'assignment_id' => $assignment?->id]);
            School::record($parent, 'message', 'Sent a message', $assignment);

            return $message->fresh();
        });
    }

    public static function copy(User $parent, string $from, string $to): array
    {
        self::authorize($parent);
        Validator::make(compact('from', 'to'), ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|different:from'])->validate();
        $student = self::student();

        return DB::transaction(function () use ($parent, $student, $from, $to): array {
            return Assignment::where('is_extra_practice', false)->where('student_id', $student->id)->whereDate('date', $from)->orderBy('position')->orderBy('id')->get()->map(function (Assignment $assignment) use ($parent, $to): Assignment {
                return self::create($parent, array_replace($assignment->only(self::ASSIGNMENT_FIELDS), ['date' => $to]));
            })->all();
        });
    }

    public static function reorder(User $parent, string $date, array $ids): array
    {
        self::authorize($parent);
        Validator::make(compact('date', 'ids'), ['date' => 'required|date_format:Y-m-d', 'ids' => 'required|array|min:1', 'ids.*' => 'required|integer|distinct'])->validate();

        return DB::transaction(function () use ($parent, $date, $ids): array {
            $assignments = Assignment::where('is_extra_practice', false)->where('student_id', self::student()->id)->whereDate('date', $date)->lockForUpdate()->get();
            abort_unless($assignments->count() === count($ids) && $assignments->pluck('id')->diff($ids)->isEmpty(), 422, 'Supply every assignment ID for the date exactly once.');
            foreach ($ids as $position => $id) {
                Assignment::whereKey($id)->update(['position' => $position]);
            }
            School::record($parent, 'plan_reordered', 'Reordered assignments for '.$date);

            return $ids;
        });
    }

    public static function recurrence(User $parent, array $data): Recurrence
    {
        self::authorize($parent);
        Validator::make(['rule' => $data], [
            'rule' => 'required|array:'.implode(',', self::ASSIGNMENT_FIELDS).',weekdays,ends_on',
            'rule.date' => 'required|date_format:Y-m-d',
            'rule.weekdays' => 'required|array|min:1|max:7',
            'rule.weekdays.*' => 'required|integer|between:1,7|distinct',
            'rule.ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:rule.date',
        ])->validate();
        $assignmentData = self::validateAssignment(self::assignmentDefaults(array_intersect_key($data, array_flip(self::ASSIGNMENT_FIELDS))) + ['requires_photo' => false, 'position' => 0]);
        unset($assignmentData['date']);

        return DB::transaction(function () use ($parent, $data, $assignmentData): Recurrence {
            $rule = Recurrence::create(['student_id' => self::student()->id, 'starts_on' => $data['date'], 'ends_on' => $data['ends_on'] ?? null, 'weekdays' => $data['weekdays'], 'assignment_data' => $assignmentData]);
            School::generate($data['date']);
            School::record($parent, 'recurrence_created', 'Created recurring assignment: '.$assignmentData['title']);

            return $rule;
        });
    }

    public static function stopRecurrence(User $parent, int $id): Recurrence
    {
        self::authorize($parent);
        $rule = Recurrence::where('student_id', self::student()->id)->findOrFail($id);
        $rule->update(['active' => false]);
        School::record($parent, 'recurrence_stopped', 'Stopped recurring assignment: '.$rule->assignment_data['title']);

        return $rule;
    }

    public static function assignmentDefaults(array $data): array
    {
        if (($data['activity_type'] ?? '') === 'spelling') {
            $data += ['subject' => 'Spelling', 'title' => 'Spelling practice', 'instructions' => 'Look, listen, and choose. Practice this week’s words.', 'minutes' => 5, 'requires_photo' => false];
        }
        if (($data['activity_type'] ?? '') === 'multiplication') {
            $data += ['subject' => 'Math', 'title' => 'Multiplication practice', 'instructions' => 'Practice accurate recall, one fact at a time.', 'minutes' => 5, 'requires_photo' => false, 'fact_min' => 1, 'fact_max' => 12];
        }
        if (! empty($data['book_id'])) {
            Validator::make($data, ['book_id' => 'required|integer|exists:books,id'])->validate();
            $book = Book::where('student_id', self::student()->id)->findOrFail($data['book_id']);
            $data += $book->only(['subject', 'title', 'reference', 'minutes', 'requires_photo']);
            $data += ['instructions' => $book->instructions ?: 'Complete the assigned lesson or pages.'];
        }

        return $data;
    }

    public static function saveBook(User $parent, array $patch, ?int $id = null): Book
    {
        self::authorize($parent);
        $student = self::student();
        $book = $id === null ? null : Book::where('student_id', $student->id)->findOrFail($id);
        $fields = ['subject', 'title', 'publisher', 'level', 'reference', 'instructions', 'minutes', 'requires_photo', 'active'];
        $data = $book === null ? $patch + ['requires_photo' => false, 'active' => true] : array_replace($book->only($fields), $patch);
        $data = Validator::make(['book' => $data], [
            'book' => 'required|array:'.implode(',', $fields),
            'book.subject' => 'required|string|max:60', 'book.title' => 'required|string|max:160',
            'book.publisher' => 'nullable|string|max:160', 'book.level' => 'nullable|string|max:100',
            'book.reference' => 'nullable|string|max:160', 'book.instructions' => 'nullable|string|max:8000',
            'book.minutes' => 'nullable|integer|min:1|max:480', 'book.requires_photo' => 'required|boolean', 'book.active' => 'required|boolean',
        ])->validate()['book'];

        return DB::transaction(function () use ($parent, $student, $book, $data): Book {
            if ($book === null) {
                $book = Book::create($data + ['student_id' => $student->id]);
            } else {
                $book->update($data);
            }
            School::record($parent, 'book_updated', 'Saved book: '.$book->title);

            return $book->fresh();
        });
    }
}
