<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Game;
use App\Models\PracticeAttempt;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\DailyRoutine;
use App\Services\GameLibrary;
use App\Services\ParentActions;
use App\Services\PracticeGame;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GameLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function family(): array
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00', 'America/Chicago'));
        $parent = User::factory()->create(['role' => 'parent']);
        $student = User::factory()->create(['role' => 'student']);
        $game = Game::factory()->create(['student_id' => $student->id]);

        return [$parent, $student, $game];
    }

    private function play(User $student, Game $game): Assignment
    {
        $this->actingAs($student)->post('/games/'.$game->id.'/play', ['version' => $student->fresh()->routine_version])->assertRedirect();

        return Assignment::latest('id')->firstOrFail();
    }

    private function answer(User $student, Assignment $round): void
    {
        $payload = PracticeGame::act($student->fresh(), $round, ['action' => 'start', 'version' => $student->fresh()->routine_version]);
        for ($i = 0; $i < 2; $i++) {
            $question = $payload['question'];
            $this->travel(2)->seconds();
            $payload = PracticeGame::act($student->fresh(), $round, ['action' => 'answer', 'version' => $student->fresh()->routine_version, 'attempt_id' => $question['id'], 'answer' => $question['left'] * $question['right'], 'response_ms' => 2000]);
            if ($i === 0) {
                $payload = PracticeGame::act($student->fresh(), $round, ['action' => 'next', 'version' => $student->fresh()->routine_version]);
            }
        }
        $this->assertTrue($payload['complete']);
    }

    public function test_multiple_rounds_preserve_results_and_record_time_without_required_work_or_review_queue(): void
    {
        [$parent, $student, $game] = $this->family();
        DailyRoutine::transition($student, 'day-start', 0);
        $first = $this->play($student, $game);
        $this->assertTrue($first->is_extra_practice);
        $this->answer($student, $first);
        $second = $this->play($student, $game);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('submitted', $first->fresh()->status);
        $this->assertSame(2, PracticeAttempt::where('assignment_id', $first->id)->whereNotNull('answered_at')->count());
        $this->assertSame(4000, (int) WorkSession::where('assignment_id', $first->id)->sum('practice_ms'));
        $this->assertSame(2, PracticeGame::facts($student)['totals']['attempts']);
        $this->get('/routine/finish')->assertOk()->assertSee('0 assignments still to finish')->assertSee('0 assignments awaiting Dad');
        $this->assertSame(0, Artisan::call('schoolday', ['action' => 'status', '--compact' => true]));
        $status = json_decode(Artisan::output(), true)['data'];
        $this->assertSame(0, $status['progress']['total']);
        $this->assertCount(2, $status['extra_practice']);
        $this->assertSame([], $status['awaiting_review']);
        $this->assertSame([], ParentActions::copy($parent, today()->toDateString(), '2026-11-03'));
        $this->assertCount(2, Game::with('rounds')->find($game->id)->rounds);
        $this->actingAs($parent)->get('/games')->assertOk()->assertSee('Edit game')->assertDontSee('game-mode');
    }

    public function test_optional_rounds_do_not_break_reordering_and_snapshots_survive_game_edits(): void
    {
        [$parent, $student, $game] = $this->family();
        DailyRoutine::transition($student, 'day-start', 0);
        $round = $this->play($student, $game);
        $required = ParentActions::create($parent, ['date' => today()->toDateString(), 'title' => 'Required work', 'subject' => 'Math', 'instructions' => 'Complete the lesson.']);
        ParentActions::reorder($parent, today()->toDateString(), [$required->id]);
        GameLibrary::save($parent, ['title' => 'Six times table', 'fact_min' => 6, 'fact_max' => 6], $game->id);
        $this->assertSame(1, $round->fresh()->fact_min);
        $this->assertSame(12, $round->fresh()->fact_max);
        $next = $this->play($student, $game);
        $this->assertSame(6, $next->fact_min);
        $this->assertSame(6, $next->fact_max);
        $this->assertSame('Six times table', $next->title);
        $this->assertDatabaseCount('work_sessions', 3);
    }

    public function test_play_requires_own_student_open_day_active_game_and_fresh_version_without_orphan_rounds(): void
    {
        [$parent, $student, $game] = $this->family();
        $url = '/games/'.$game->id.'/play';
        $this->post($url, ['version' => 0])->assertRedirect('/login');
        $this->actingAs($parent)->post($url, ['version' => 0])->assertForbidden();
        $other = User::factory()->create(['role' => 'student']);
        $this->actingAs($other)->post($url, ['version' => 0])->assertForbidden();
        $this->actingAs($student)->post($url, ['version' => 0])->assertConflict();
        $this->assertDatabaseCount('assignments', 0);
        DailyRoutine::transition($student, 'day-start', 0);
        $version = $student->fresh()->routine_version;
        $this->play($student, $game);
        $this->post($url, ['version' => $version])->assertConflict();
        $this->assertDatabaseCount('assignments', 1);
        GameLibrary::save($parent, ['active' => false], $game->id);
        $this->post($url, ['version' => $student->fresh()->routine_version])->assertConflict();
        $this->assertDatabaseCount('assignments', 1);
        $this->get('/games')->assertOk()->assertDontSee('action="'.route('games.play', $game).'"', false);
    }

    public function test_parent_can_add_weekly_spelling_games_via_cli_with_validation_and_retry_safe_dry_runs(): void
    {
        [$parent, $student] = $this->family();
        $file = tempnam(sys_get_temp_dir(), 'library-');
        file_put_contents($file, json_encode(['activity_type' => 'spelling', 'title' => 'Week one words', 'spelling_words' => [' Friend ', 'because'], 'minutes' => 5]));
        try {
            $this->assertSame(0, Artisan::call('schoolday', ['action' => 'game-add', '--file' => $file, '--dry-run' => true, '--compact' => true]));
            $this->assertDatabaseCount('games', 1);
            $args = ['action' => 'game-add', '--file' => $file, '--key' => 'week-one-words', '--compact' => true];
            $this->assertSame(0, Artisan::call('schoolday', $args));
            $this->assertSame(0, Artisan::call('schoolday', $args));
            $game = Game::latest('id')->first();
            $this->assertSame(['friend', 'because'], $game->configuration['spelling_words']);
            $this->assertDatabaseCount('games', 2);
            file_put_contents($file, json_encode(['active' => false]));
            $this->assertSame(0, Artisan::call('schoolday', ['action' => 'game-update', 'target' => $game->id, '--file' => $file, '--compact' => true]));
            $this->assertFalse($game->fresh()->active);
        } finally {
            unlink($file);
        }
        $this->actingAs($student)->postJson('/parent/games', ['activity_type' => 'multiplication'])->assertForbidden();
        $this->actingAs($parent)->postJson('/parent/games', ['activity_type' => 'spelling', 'spelling_words' => 'bad word', 'title' => 'Words'])->assertUnprocessable();
        $this->postJson('/parent/games', ['activity_type' => 'multiplication', 'fact_min' => 12, 'fact_max' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('games', 2);
    }

    public function test_student_has_game_mode_and_drawer_but_parent_keeps_normal_review_interface(): void
    {
        [$parent, $student, $game] = $this->family();
        DailyRoutine::transition($student, 'day-start', 0);
        $round = $this->play($student, $game);
        $this->get('/practice/'.$round->id)->assertOk()->assertSee('Game mode')->assertSee('Pick a game')->assertSee('Play a new round');
        $this->actingAs($parent)->get('/practice/'.$round->id)->assertOk()->assertSee('Review the practice')->assertDontSee('Game mode');
    }
}
