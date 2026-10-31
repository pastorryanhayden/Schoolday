<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Assignment;
use App\Models\PracticeAttempt;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\DailyRoutine;
use App\Services\ParentActions;
use App\Services\PracticeGame;
use App\Services\School;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MultiplicationPracticeTest extends TestCase
{
    use RefreshDatabase;

    private function family(): array
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00', 'America/Chicago'));
        $parent = User::factory()->create(['role' => 'parent']);
        $student = User::factory()->create(['role' => 'student']);
        $game = ParentActions::create($parent, ['date' => today()->toDateString(), 'activity_type' => 'multiplication', 'minutes' => 1, 'fact_min' => 6, 'fact_max' => 6]);

        return [$parent, $student, $game];
    }

    private function openDay(User $student): void
    {
        $this->actingAs($student)->post('/routine', ['action' => 'day-start', 'version' => $student->fresh()->routine_version])->assertRedirect();
    }

    private function act(User $student, Assignment $game, string $action, array $data = []): array
    {
        return $this->actingAs($student)->postJson('/practice/'.$game->id, ['action' => $action, 'version' => $student->fresh()->routine_version] + $data)->assertOk()->json();
    }

    private function answer(User $student, Assignment $game, array $question, int $seconds, ?int $answer = null): array
    {
        $this->travel($seconds)->seconds();

        return $this->act($student, $game, 'answer', ['attempt_id' => $question['id'], 'answer' => $answer ?? $question['left'] * $question['right'], 'response_ms' => $seconds * 1000]);
    }

    public function test_parent_can_schedule_configure_copy_and_recur_practice_with_five_minute_defaults(): void
    {
        [$parent, $student, $game] = $this->family();
        $this->actingAs($parent)->post('/parent/assignments', ['activity_type' => 'multiplication', 'date' => '2026-11-03', 'subject' => 'Math', 'title' => 'Facts practice', 'instructions' => 'Recall facts.', 'minutes' => 5, 'requires_photo' => 0, 'position' => 0, 'fact_min' => 2, 'fact_max' => 9])->assertRedirect();
        $scheduled = Assignment::latest('id')->first();
        $this->assertSame('multiplication', $scheduled->activity_type);
        $this->assertSame(2, $scheduled->fact_min);
        ParentActions::copy($parent, '2026-11-03', '2026-11-04');
        $this->assertSame(9, Assignment::latest('id')->first()->fact_max);
        $rule = ParentActions::recurrence($parent, ['activity_type' => 'multiplication', 'date' => '2026-11-02', 'weekdays' => [1, 2, 3, 4, 5]]);
        School::generate('2026-11-05');
        $generated = Assignment::where('recurrence_id', $rule->id)->whereDate('date', '2026-11-05')->firstOrFail();
        $this->assertSame(5, $generated->minutes);
        $this->assertSame('multiplication', $generated->activity_type);
        $this->actingAs($student)->get('/assignments/'.$game->id)->assertRedirect('/practice/'.$game->id);
        $this->get('/practice/'.$game->id)->assertOk()->assertSee('Start my day first')->assertDontSee('Multiplication fact history');
        $this->actingAs($parent)->get('/practice/'.$game->id)->assertOk()->assertSee('Practice results')->assertSee('Multiplication fact history');
    }

    public function test_invalid_game_settings_are_rejected_without_creating_work(): void
    {
        [$parent] = $this->family();
        foreach ([['question_target' => 0], ['question_target' => 101], ['minutes' => 0], ['minutes' => 16], ['fact_min' => 0], ['fact_max' => 13], ['fact_min' => 9, 'fact_max' => 3], ['requires_photo' => true]] as $patch) {
            $file = tempnam(sys_get_temp_dir(), 'game-plan-');
            file_put_contents($file, json_encode(['date' => '2026-11-02', 'assignments' => [$patch + ['activity_type' => 'multiplication']]]));
            try {
                $this->assertSame(2, Artisan::call('schoolday', ['action' => 'plan', '--file' => $file, '--compact' => true]));
            } finally {
                unlink($file);
            }
        }
        $this->assertDatabaseCount('assignments', 1);
    }

    public function test_question_target_finishes_on_the_thirtieth_answer_even_after_the_time_target(): void
    {
        [$parent, $student, $game] = $this->family();
        ParentActions::update($parent, $game->id, ['question_target' => 30]);
        $game->refresh();
        $this->openDay($student);
        $payload = $this->act($student, $game, 'start');
        for ($number = 1; $number <= 30; $number++) {
            $payload = $this->answer($student, $game, $payload['question'], 3);
            $this->assertSame($number === 30, $payload['complete']);
            if ($number < 30) {
                $payload = $this->act($student, $game, 'next');
            }
        }
        $this->assertSame(30, $payload['summary']['question_target']);
        $this->assertSame(30, $payload['summary']['round']['attempts']);
        $this->assertSame(90000, $payload['summary']['round']['practice_ms']);
        $this->assertSame('submitted', $game->fresh()->status);
        ParentActions::copy($parent, '2026-11-02', '2026-11-03');
        $this->assertSame(30, Assignment::latest('id')->first()->question_target);
    }

    public function test_only_own_student_can_play_and_a_day_must_be_started(): void
    {
        [$parent, $student, $game] = $this->family();
        $url = '/practice/'.$game->id;
        $data = ['action' => 'start', 'version' => 0];
        $this->get($url)->assertRedirect('/login');
        $this->actingAs($parent)->postJson($url, $data)->assertForbidden();
        $other = User::factory()->create(['role' => 'student']);
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->postJson($url, $data)->assertForbidden();
        $this->actingAs($student)->postJson($url, $data)->assertConflict();
        $plain = ParentActions::create($parent, ['date' => '2026-11-02', 'subject' => 'Math', 'title' => 'Lesson', 'instructions' => 'Read.']);
        $this->get('/practice/'.$plain->id)->assertNotFound();
        $this->assertDatabaseCount('practice_attempts', 0);
        $this->assertSame(0, $student->fresh()->routine_version);
    }

    public function test_questions_are_server_graded_and_target_completion_submits_without_approval(): void
    {
        [, $student, $game] = $this->family();
        $this->openDay($student);
        $first = $this->act($student, $game, 'start');
        $this->assertSame(['id', 'left', 'right'], array_keys($first['question']));
        $this->assertSame(6, $first['question']['left']);
        $this->assertSame(6, $first['question']['right']);
        $this->post('/assignments/'.$game->id.'/submit')->assertConflict();
        $result = $this->answer($student, $game, $first['question'], 30, 35);
        $this->assertFalse($result['result']['correct']);
        $this->assertSame(36, $result['result']['answer']);
        $this->assertFalse($result['complete']);
        $next = $this->act($student, $game, 'next');
        $result = $this->answer($student, $game, $next['question'], 30);
        $this->assertTrue($result['complete']);
        $this->assertSame(['attempts' => 2, 'correct' => 1, 'accuracy' => 50, 'practice_ms' => 60000, 'median_correct_ms' => 30000, 'help_uses' => 0], $result['summary']['round']);
        $this->assertSame('submitted', $game->fresh()->status);
        $this->assertSame(1, $game->fresh()->revision);
        $this->assertNull($game->fresh()->approved_at);
        $this->assertNull($student->fresh()->current_assignment_id);
        $this->assertSame('idle', $result['state']['current']['kind']);
        $this->assertSame(60, DailyRoutine::report($student, '2026-11-02')['totals_seconds']['work']);
        $this->assertDatabaseHas('activities', ['kind' => 'practice_complete', 'assignment_id' => $game->id]);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_open_page_and_pauses_do_not_add_practice_time_and_old_question_cannot_be_answered(): void
    {
        [, $student, $game] = $this->family();
        $this->openDay($student);
        $first = $this->act($student, $game, 'start');
        $this->travel(10)->minutes();
        $this->assertSame(0, DailyRoutine::report($student, '2026-11-02')['totals_seconds']['work']);
        $this->assertSame(0, PracticeGame::summary($game)['round']['practice_ms']);
        $this->act($student, $game, 'pause');
        $this->assertTrue(PracticeAttempt::find($first['question']['id'])->interrupted);
        $this->travel(10)->minutes();
        $new = $this->act($student, $game, 'start');
        $this->assertNotSame($first['question']['id'], $new['question']['id']);
        $this->postJson('/practice/'.$game->id, ['action' => 'answer', 'version' => $student->fresh()->routine_version, 'attempt_id' => $first['question']['id'], 'answer' => 36, 'response_ms' => 1000])->assertNotFound();
        $this->assertSame(0, PracticeGame::summary($game)['round']['attempts']);
        $this->assertSame(0, DailyRoutine::report($student, '2026-11-02')['totals_seconds']['work']);
    }

    public function test_answers_are_retry_safe_and_a_stale_device_cannot_score_or_replace_newer_activity(): void
    {
        [$parent, $student, $game] = $this->family();
        $this->openDay($student);
        $question = $this->act($student, $game, 'start')['question'];
        $version = $student->fresh()->routine_version;
        $this->travel(3)->seconds();
        $data = ['action' => 'answer', 'version' => $version, 'attempt_id' => $question['id'], 'answer' => 36, 'response_ms' => 3000];
        $this->postJson('/practice/'.$game->id, $data)->assertOk();
        $newVersion = $student->fresh()->routine_version;
        $this->postJson('/practice/'.$game->id, $data)->assertOk();
        $this->assertSame($newVersion, $student->fresh()->routine_version);
        $this->assertSame(3000, PracticeGame::summary($game)['round']['practice_ms']);
        $this->postJson('/practice/'.$game->id, array_replace($data, ['answer' => 35]))->assertConflict();
        $next = $this->act($student, $game, 'next')['question'];
        $other = ParentActions::create($parent, ['date' => '2026-11-02', 'subject' => 'Writing', 'title' => 'Paragraph', 'instructions' => 'Write.']);
        DailyRoutine::transition($student, 'activity-start', $student->fresh()->routine_version, $other->id);
        $this->postJson('/practice/'.$game->id, ['action' => 'answer', 'version' => $newVersion, 'attempt_id' => $next['id'], 'answer' => 36, 'response_ms' => 1000])->assertConflict();
        $this->postJson('/practice/'.$game->id, $data)->assertOk()->assertJsonPath('running', false)->assertJsonPath('state.current.assignment_id', $other->id);
        $this->assertSame($other->id, $student->fresh()->current_assignment_id);
        $this->assertSame(1, PracticeAttempt::whereNotNull('answered_at')->count());
    }

    public function test_invalid_timing_and_answers_do_not_credit_time_and_very_slow_answers_are_capped(): void
    {
        [, $student, $game] = $this->family();
        $this->openDay($student);
        $question = $this->act($student, $game, 'start')['question'];
        $data = ['action' => 'answer', 'version' => $student->fresh()->routine_version, 'attempt_id' => $question['id'], 'answer' => 36, 'response_ms' => 30000];
        $this->postJson('/practice/'.$game->id, $data)->assertConflict();
        $this->postJson('/practice/'.$game->id, array_replace($data, ['response_ms' => 0]))->assertUnprocessable();
        $this->postJson('/practice/'.$game->id, array_replace($data, ['answer' => -1]))->assertUnprocessable();
        $this->assertSame(0, PracticeGame::summary($game)['round']['practice_ms']);
        $this->travel(90)->seconds();
        $this->postJson('/practice/'.$game->id, array_replace($data, ['response_ms' => 90000]))->assertOk();
        $this->assertSame(90000, PracticeAttempt::find($question['id'])->response_ms);
        $this->assertSame(60000, PracticeAttempt::find($question['id'])->credited_ms);
    }

    public function test_breaks_require_a_fresh_question_and_repeated_start_does_not_lose_recorded_progress(): void
    {
        [, $student, $game] = $this->family();
        $this->openDay($student);
        $question = $this->act($student, $game, 'start')['question'];
        $this->answer($student, $game, $question, 5);
        $next = $this->act($student, $game, 'next')['question'];
        DailyRoutine::transition($student, 'break', $student->fresh()->routine_version);
        $this->travel(10)->minutes();
        DailyRoutine::transition($student, 'resume', $student->fresh()->routine_version);
        $this->postJson('/practice/'.$game->id, ['action' => 'answer', 'version' => $student->fresh()->routine_version, 'attempt_id' => $next['id'], 'answer' => 36, 'response_ms' => 1000])->assertNotFound();
        $again = $this->act($student, $game, 'start');
        $this->assertSame(5000, $again['summary']['round']['practice_ms']);
        $this->assertNotSame($next['id'], $again['question']['id']);
        $this->assertSame(5, DailyRoutine::report($student, '2026-11-02')['totals_seconds']['work']);
        $this->assertSame(600, DailyRoutine::report($student, '2026-11-02')['totals_seconds']['break']);
    }

    public function test_parent_feedback_requires_a_new_full_round_and_preserves_previous_results(): void
    {
        [$parent, $student, $game] = $this->family();
        $this->openDay($student);
        $question = $this->act($student, $game, 'start')['question'];
        $this->answer($student, $game, $question, 60);
        ParentActions::review($parent, $game->id, 'needs_changes', 'Another short round tomorrow.', 1);
        $resumed = $this->act($student, $game, 'start');
        $this->assertSame(2, $resumed['summary']['revision']);
        $this->assertSame(0, $resumed['summary']['round']['practice_ms']);
        $this->answer($student, $game, $resumed['question'], 60);
        $this->assertSame(2, $game->fresh()->revision);
        $this->assertCount(2, PracticeGame::summary($game->fresh())['rounds']);
        ParentActions::review($parent, $game->id, 'approved', 'Good practice.', 2);
        $this->postJson('/practice/'.$game->id, ['action' => 'start', 'version' => $student->fresh()->routine_version])->assertConflict();
        $this->assertSame('approved', $game->fresh()->status);
    }

    public function test_fact_selection_stays_in_range_and_avoids_immediate_repetition_when_possible(): void
    {
        [$parent, $student] = $this->family();
        $game = ParentActions::create($parent, ['date' => '2026-11-02', 'activity_type' => 'multiplication', 'fact_min' => 2, 'fact_max' => 3]);
        $this->openDay($student);
        $question = $this->act($student, $game, 'start')['question'];
        $previous = null;
        for ($i = 0; $i < 9; $i++) {
            $fact = [min($question['left'], $question['right']), max($question['left'], $question['right'])];
            $this->assertContains($question['left'], [2, 3]);
            $this->assertContains($question['right'], [2, 3]);
            $this->assertNotSame($previous, $fact);
            $this->answer($student, $game, $question, 1);
            $previous = $fact;
            $question = $this->act($student, $game, 'next')['question'];
        }
    }

    public function test_fact_reports_distinguish_accuracy_from_correct_recall_speed_and_are_available_in_cli(): void
    {
        [, $student, $game] = $this->family();
        $this->openDay($student);
        $question = $this->act($student, $game, 'start')['question'];
        $this->answer($student, $game, $question, 2);
        $question = $this->act($student, $game, 'next')['question'];
        $this->answer($student, $game, $question, 1, 0);
        $facts = PracticeGame::facts($student);
        $this->assertSame(50.0, $facts['facts'][0]['accuracy']);
        $this->assertSame(2000, $facts['facts'][0]['recent_correct_median_ms']);
        $this->assertFalse($facts['facts'][0]['quick_recently']);
        $version = $student->fresh()->routine_version;
        $this->assertSame(0, Artisan::call('schoolday', ['action' => 'facts', '--compact' => true]));
        $this->assertSame(2, json_decode(Artisan::output(), true)['data']['totals']['attempts']);
        $this->assertSame(0, Artisan::call('schoolday', ['action' => 'practice', 'target' => $game->id, '--compact' => true]));
        $this->assertSame(3000, json_decode(Artisan::output(), true)['data']['round']['practice_ms']);
        $this->assertSame($version, $student->fresh()->routine_version);
        $this->assertSame(0, Artisan::call('schoolday', ['action' => 'schema', 'target' => 'multiplication', '--compact' => true]));
    }

    public function test_brief_feedback_counts_toward_the_target_without_changing_recall_speed(): void
    {
        [, $student, $game] = $this->family();
        $this->openDay($student);
        $question = $this->act($student, $game, 'start')['question'];
        $this->answer($student, $game, $question, 55);
        $this->travel(5)->seconds();
        $result = $this->act($student, $game, 'next', ['attempt_id' => $question['id'], 'feedback_ms' => 5000]);
        $this->assertTrue($result['complete']);
        $this->assertSame(60000, $result['summary']['round']['practice_ms']);
        $this->assertSame(55000, $result['summary']['round']['median_correct_ms']);
        $this->assertSame(5000, PracticeAttempt::find($question['id'])->feedback_ms);
        $this->assertTrue(PracticeAttempt::find($question['id'])->feedback_recorded);
        $this->assertSame(60, DailyRoutine::report($student, '2026-11-02')['totals_seconds']['work']);
    }

    public function test_reversed_pairs_share_fact_history_and_expired_questions_do_not_score(): void
    {
        [, $student, $game] = $this->family();
        $this->openDay($student);
        $question = $this->act($student, $game, 'start')['question'];
        $session = WorkSession::where('assignment_id', $game->id)->first();
        foreach ([[7, 8, 2000], [8, 7, 4000]] as [$left, $right, $speed]) {
            PracticeAttempt::create(['student_id' => $student->id, 'assignment_id' => $game->id, 'work_session_id' => $session->id, 'revision' => 1, 'left' => $left, 'right' => $right, 'issued_at' => now(), 'answered_at' => now(), 'correct' => true, 'answer' => 56, 'response_ms' => $speed, 'credited_ms' => $speed]);
        }
        $facts = PracticeGame::facts($student)['facts'];
        $this->assertCount(1, $facts);
        $this->assertSame('7 × 8', $facts[0]['fact']);
        $this->assertSame(2, $facts[0]['attempts']);
        $this->assertSame(3000, $facts[0]['recent_correct_median_ms']);
        $this->travel(121)->seconds();
        $this->postJson('/practice/'.$game->id, ['action' => 'answer', 'version' => $student->fresh()->routine_version, 'attempt_id' => $question['id'], 'answer' => 36, 'response_ms' => 1000])->assertConflict();
        $this->assertNull(PracticeAttempt::find($question['id'])->answered_at);
    }

    public function test_cli_can_plan_a_game_with_defaults_and_replay_it_without_duplicate_assignments(): void
    {
        $this->family();
        $file = tempnam(sys_get_temp_dir(), 'game-plan-');
        file_put_contents($file, json_encode(['date' => '2026-11-02', 'assignments' => [['activity_type' => 'multiplication']]]));
        try {
            $args = ['action' => 'plan', '--file' => $file, '--key' => 'math-facts-daily', '--compact' => true];
            $this->assertSame(0, Artisan::call('schoolday', $args));
            $this->assertSame(0, Artisan::call('schoolday', $args));
            $this->assertTrue(json_decode(Artisan::output(), true)['meta']['replayed']);
            $game = Assignment::latest('id')->first();
            $this->assertSame(5, $game->minutes);
            $this->assertSame(1, $game->fact_min);
            $this->assertSame(12, $game->fact_max);
            $this->assertDatabaseCount('assignments', 2);
        } finally {
            unlink($file);
        }
    }

    public function test_chart_use_is_recorded_once_and_assisted_correct_answers_do_not_count_as_independent_speed(): void
    {
        [, $student, $game] = $this->family();
        $this->openDay($student);
        $question = $this->act($student, $game, 'start')['question'];
        $this->act($student, $game, 'help', ['attempt_id' => $question['id']]);
        $firstHelp = PracticeAttempt::find($question['id'])->assisted_at;
        $this->travel(1)->seconds();
        $this->act($student, $game, 'help', ['attempt_id' => $question['id']]);
        $this->assertTrue($firstHelp->equalTo(PracticeAttempt::find($question['id'])->assisted_at));
        $result = $this->answer($student, $game, $question, 2);
        $this->assertTrue($result['result']['correct']);
        $this->assertTrue($result['result']['assisted']);
        $this->assertSame(1, $result['summary']['round']['help_uses']);
        $this->assertNull($result['summary']['round']['median_correct_ms']);
        $this->assertSame(1, $result['summary']['round']['correct']);
        $facts = PracticeGame::facts($student)['facts'];
        $this->assertTrue($facts[0]['needs_drill']);
        $this->assertFalse($facts[0]['quick_recently']);
        $this->assertNull($facts[0]['recent_correct_median_ms']);
        $this->assertSame(1, $facts[0]['help_uses']);
        $this->assertDatabaseCount('practice_attempts', 1);
        $this->assertSame(1, Activity::where('kind', 'practice_help')->count());
    }

    public function test_unanswered_chart_help_remains_flagged_and_stale_or_foreign_questions_cannot_request_help(): void
    {
        [$parent, $student, $game] = $this->family();
        $this->openDay($student);
        $question = $this->act($student, $game, 'start')['question'];
        $oldVersion = $student->fresh()->routine_version;
        $this->act($student, $game, 'help', ['attempt_id' => $question['id']]);
        $this->postJson('/practice/'.$game->id, ['action' => 'help', 'version' => $oldVersion, 'attempt_id' => $question['id']])->assertConflict();
        $this->act($student, $game, 'pause');
        $facts = PracticeGame::facts($student)['facts'];
        $this->assertCount(1, $facts);
        $this->assertSame(0, $facts[0]['attempts']);
        $this->assertSame(1, $facts[0]['help_uses']);
        $this->assertTrue($facts[0]['needs_drill']);
        $this->assertSame(0, $facts[0]['practice_ms']);
        $this->act($student, $game, 'start');
        $this->postJson('/practice/'.$game->id, ['action' => 'help', 'version' => $student->fresh()->routine_version, 'attempt_id' => $question['id']])->assertNotFound();
        $this->actingAs($parent)->postJson('/practice/'.$game->id, ['action' => 'help', 'version' => $student->fresh()->routine_version, 'attempt_id' => $question['id']])->assertForbidden();
        $this->get('/practice/'.$game->id)->assertOk()->assertSee('Chart used 1 time');
    }

    public function test_timeline_corrections_cannot_fill_the_practice_target(): void
    {
        [$parent, $student, $game] = $this->family();
        $this->openDay($student);
        $question = $this->act($student, $game, 'start')['question'];
        $this->answer($student, $game, $question, 5);
        $session = WorkSession::where('assignment_id', $game->id)->first();
        $this->travel(1)->hours();
        DailyRoutine::correct($parent, $session->school_day_id, $session->id, ['version' => $student->fresh()->routine_version, 'started_at' => '2026-11-02T08:00:00', 'ended_at' => '2026-11-02T09:00:00', 'reason' => 'Forgot to stop the page.']);
        $this->assertSame(5, DailyRoutine::report($student, '2026-11-02')['totals_seconds']['work']);
        $this->assertSame(5000, PracticeGame::summary($game)['round']['practice_ms']);
        $this->assertSame('in_progress', $game->fresh()->status);
    }
}
