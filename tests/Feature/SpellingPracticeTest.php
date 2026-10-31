<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\DailyRoutine;
use App\Services\ParentActions;
use App\Services\PracticeGame;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SpellingPracticeTest extends TestCase
{
    use RefreshDatabase;

    private function family(array $words = ['because'], int $target = 3): array
    {
        $this->travelTo(Carbon::parse('2026-10-31 08:00:00', 'America/Chicago'));
        $parent = User::factory()->create(['role' => 'parent']);
        $student = User::factory()->create(['role' => 'student']);
        $game = ParentActions::create($parent, ['date' => today()->toDateString(), 'activity_type' => 'spelling', 'spelling_words' => $words, 'question_target' => $target]);

        return [$parent, $student, $game];
    }

    private function act(User $student, Assignment $game, string $action, array $data = []): array
    {
        return $this->actingAs($student)->postJson('/practice/'.$game->id, ['action' => $action, 'version' => $student->fresh()->routine_version] + $data)->assertOk()->json();
    }

    private function start(User $student, Assignment $game): array
    {
        DailyRoutine::transition($student, 'day-start', $student->fresh()->routine_version);

        return $this->act($student, $game, 'start');
    }

    private function answer(User $student, Assignment $game, int $attempt, string $text): array
    {
        $this->travel(2)->seconds();

        return $this->act($student, $game, 'answer', ['attempt_id' => $attempt, 'answer' => $text, 'response_ms' => 2000]);
    }

    public function test_rotates_all_three_games_grades_on_server_and_records_time_and_separate_results(): void
    {
        [$parent, $student, $game] = $this->family();
        $payload = $this->start($student, $game);
        $this->assertSame('study', $payload['question']['mode']);
        $this->assertSame('because', $payload['question']['word']);
        $id = $payload['question']['id'];
        $this->postJson('/practice/'.$game->id, ['action' => 'answer', 'version' => $student->fresh()->routine_version, 'attempt_id' => $id, 'answer' => 'because', 'response_ms' => 100])->assertConflict();
        $covered = $this->act($student, $game, 'cover', ['attempt_id' => $id]);
        $this->assertNull($covered['question']['word']);
        $payload = $this->answer($student, $game, $id, ' BECAUSE ');
        $this->assertTrue($payload['result']['correct']);
        $this->assertFalse($payload['complete']);

        $payload = $this->act($student, $game, 'next');
        $this->assertSame('listen', $payload['question']['mode']);
        $this->assertNull($payload['question']['word']);
        $id = $payload['question']['id'];
        $heard = $this->act($student, $game, 'listen', ['attempt_id' => $id]);
        $this->assertSame('because', $heard['speech']);
        $this->act($student, $game, 'listen', ['attempt_id' => $id]);
        $payload = $this->answer($student, $game, $id, 'becuase');
        $this->assertFalse($payload['result']['correct']);
        $this->assertSame('because', $payload['result']['answer']);
        $retry = $this->act($student, $game, 'answer', ['attempt_id' => $id, 'answer' => 'becuase', 'response_ms' => 2000]);
        $this->assertSame(2, $retry['summary']['round']['attempts']);

        $payload = $this->act($student, $game, 'next');
        $this->assertSame('choose', $payload['question']['mode']);
        $this->assertCount(3, array_unique($payload['question']['choices']));
        $this->assertContains('because', $payload['question']['choices']);
        $id = $payload['question']['id'];
        $this->act($student, $game, 'listen', ['attempt_id' => $id]);
        $payload = $this->answer($student, $game, $id, 'because');
        $this->assertTrue($payload['complete']);
        $this->assertSame(6000, $payload['summary']['round']['practice_ms']);
        $this->assertSame('submitted', $game->fresh()->status);
        $this->assertSame(0, PracticeGame::facts($student)['totals']['attempts']);
        $this->assertSame(6000, (int) WorkSession::where('assignment_id', $game->id)->sum('practice_ms'));
        $this->assertSame(3, count(PracticeGame::report($game)['modes']));
        $this->assertSame(1, PracticeGame::report($game)['words'][0]['replays']);
        $this->actingAs($parent)->get('/practice/'.$game->id)->assertOk()->assertSee('Words to revisit')->assertSee('Listen &amp; spell', false);
        $this->assertSame(0, Artisan::call('schoolday', ['action' => 'practice', 'target' => $game->id, '--compact' => true]));
        $this->assertCount(3, json_decode(Artisan::output(), true)['data']['modes']);
    }

    public function test_peeks_remain_flagged_when_unanswered_and_pause_stale_devices_cannot_add_time(): void
    {
        [, $student, $game] = $this->family();
        $payload = $this->start($student, $game);
        $id = $payload['question']['id'];
        $this->act($student, $game, 'cover', ['attempt_id' => $id]);
        $stale = $student->fresh()->routine_version;
        $this->act($student, $game, 'help', ['attempt_id' => $id]);
        $this->act($student, $game, 'help', ['attempt_id' => $id]);
        $this->postJson('/practice/'.$game->id, ['action' => 'cover', 'attempt_id' => $id, 'version' => $stale])->assertConflict();
        $this->act($student, $game, 'pause');
        $this->postJson('/practice/'.$game->id, ['action' => 'answer', 'attempt_id' => $id, 'version' => $student->fresh()->routine_version, 'answer' => 'because', 'response_ms' => 100])->assertConflict();
        $report = PracticeGame::report($game);
        $this->assertSame(1, $report['round']['help_uses']);
        $this->assertSame(0, $report['round']['practice_ms']);
        $this->assertTrue($report['words'][0]['needs_drill']);
    }

    public function test_list_planning_normalizes_words_preserves_copy_and_recurrence_and_rejects_bad_lists(): void
    {
        [$parent, , $game] = $this->family();
        ParentActions::update($parent, $game->id, ['spelling_words' => " Friend\nfriend\ncan't\nwell-being"]);
        $this->assertSame(['friend', "can't", 'well-being'], $game->fresh()->spelling_words);
        ParentActions::copy($parent, today()->toDateString(), '2026-11-01');
        $this->assertSame($game->fresh()->spelling_words, Assignment::latest('id')->first()->spelling_words);
        $rule = ParentActions::recurrence($parent, ['date' => '2026-11-02', 'weekdays' => [1], 'activity_type' => 'spelling', 'spelling_words' => ['friend']]);
        $this->assertSame(['friend'], $rule->assignment_data['spelling_words']);
        foreach ([[], ['<script>alert(1)</script>'], ['two words'], [str_repeat('a', 41)], range(1, 51)] as $words) {
            $this->actingAs($parent)->postJson('/parent/assignments', ['activity_type' => 'spelling', 'spelling_words' => implode("\n", $words), 'date' => '2026-11-02', 'subject' => 'Spelling', 'title' => 'Words', 'instructions' => 'Practice.', 'minutes' => 5, 'requires_photo' => false, 'position' => 0])->assertUnprocessable()->assertJsonValidationErrors('assignment.spelling_words'.($words === [] ? '' : '.0'));
        }
    }

    public function test_assisted_correct_answers_are_saved_but_excluded_from_independent_recall_and_expired_questions_fail(): void
    {
        [, $student, $game] = $this->family(["can't"], 1);
        $payload = $this->start($student, $game);
        $id = $payload['question']['id'];
        $this->act($student, $game, 'cover', ['attempt_id' => $id]);
        $this->act($student, $game, 'help', ['attempt_id' => $id]);
        $this->act($student, $game, 'cover', ['attempt_id' => $id]);
        $payload = $this->answer($student, $game, $id, ' CAN’T ');
        $this->assertTrue($payload['result']['correct']);
        $this->assertTrue($payload['result']['assisted']);
        $this->assertNull($payload['summary']['round']['median_correct_ms']);
        $this->assertSame(1, $payload['summary']['round']['help_uses']);
        $this->postJson('/practice/'.$game->id, ['action' => 'answer', 'version' => $student->fresh()->routine_version, 'attempt_id' => $id, 'answer' => 'different', 'response_ms' => 2000])->assertConflict();

        $other = ParentActions::create(User::where('role', 'parent')->first(), ['date' => today()->toDateString(), 'activity_type' => 'spelling', 'spelling_words' => ['friend']]);
        $payload = $this->act($student, $other, 'start');
        $id = $payload['question']['id'];
        $this->travel(121)->seconds();
        $this->postJson('/practice/'.$other->id, ['action' => 'cover', 'version' => $student->fresh()->routine_version, 'attempt_id' => $id])->assertConflict();
        $this->assertSame(0, PracticeGame::summary($other)['round']['practice_ms']);
    }

    public function test_cli_plans_a_weekly_list_and_each_word_gets_a_turn_before_repeating(): void
    {
        [, $student] = $this->family();
        $file = tempnam(sys_get_temp_dir(), 'spelling-plan-');
        file_put_contents($file, json_encode(['date' => today()->toDateString(), 'assignments' => [['activity_type' => 'spelling', 'spelling_words' => ['friend', 'because', 'different'], 'question_target' => 3]]]));
        try {
            $this->assertSame(0, Artisan::call('schoolday', ['action' => 'plan', '--file' => $file, '--dry-run' => true, '--compact' => true]));
            $this->assertDatabaseCount('assignments', 1);
            $this->assertSame(0, Artisan::call('schoolday', ['action' => 'plan', '--file' => $file, '--key' => 'weekly-spelling', '--compact' => true]));
        } finally {
            unlink($file);
        }
        $game = Assignment::latest('id')->first();
        $payload = $this->start($student, $game);
        $seen = [];
        foreach (['study', 'listen', 'choose'] as $index => $mode) {
            $this->assertSame($mode, $payload['question']['mode']);
            $id = $payload['question']['id'];
            if ($mode === 'study') {
                $word = $payload['question']['word'];
                $this->act($student, $game, 'cover', ['attempt_id' => $id]);
            } else {
                $heard = $this->act($student, $game, 'listen', ['attempt_id' => $id]);
                $word = $heard['speech'];
            }
            $seen[] = $word;
            $payload = $this->answer($student, $game, $id, $word);
            if ($index < 2) {
                $payload = $this->act($student, $game, 'next');
            }
        }
        $this->assertCount(3, array_unique($seen));
        $this->assertTrue($payload['complete']);
    }

    public function test_only_own_student_can_play_and_regular_submission_cannot_bypass_game(): void
    {
        [$parent, $student, $game] = $this->family();
        $url = '/practice/'.$game->id;
        $this->get($url)->assertRedirect('/login');
        $this->actingAs($parent)->postJson($url, ['action' => 'start', 'version' => 0])->assertForbidden();
        $other = User::factory()->create(['role' => 'student']);
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->postJson($url, ['action' => 'start', 'version' => 0])->assertForbidden();
        $this->actingAs($student)->get('/assignments/'.$game->id)->assertRedirect($url);
        $this->post('/assignments/'.$game->id.'/submit', ['note' => 'done'])->assertConflict();
        $this->assertSame('not_started', $game->fresh()->status);
    }
}
