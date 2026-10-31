<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Assignment;
use App\Models\SchoolDay;
use App\Models\TimelineCorrection;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\DailyRoutine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DailyRoutineTest extends TestCase
{
    use RefreshDatabase;

    private function family(): array
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00', config('app.timezone')));

        return [User::factory()->create(['role' => 'parent']), User::factory()->create(['role' => 'student'])];
    }

    private function assignment(User $student, array $data = []): Assignment
    {
        return Assignment::create($data + ['student_id' => $student->id, 'date' => today()->toDateString(), 'subject' => 'Spelling', 'title' => 'Weekly spelling', 'instructions' => 'Practice the words.', 'requires_photo' => false]);
    }

    private function action(User $student, string $action): void
    {
        $this->actingAs($student)->post('/routine', ['action' => $action, 'version' => $student->fresh()->routine_version])->assertRedirect('/');
    }

    private function start(User $student, Assignment $assignment): void
    {
        $this->actingAs($student)->post('/assignments/'.$assignment->id.'/start', ['version' => $student->fresh()->routine_version])->assertRedirect();
    }

    public function test_work_switches_breaks_and_stops_have_separate_exact_durations(): void
    {
        [$parent, $student] = $this->family();
        $spelling = $this->assignment($student, ['position' => 1]);
        $history = $this->assignment($student, ['subject' => 'History', 'title' => 'History reading', 'position' => 0]);
        $this->action($student, 'day-start');
        $this->travel(5)->minutes();
        $this->start($student, $spelling);
        $firstStart = $spelling->fresh()->started_at;
        $this->travel(25)->minutes();
        $this->action($student, 'break');
        $this->travel(10)->minutes();
        $this->start($student, $history);
        $this->travel(20)->minutes();
        $this->action($student, 'activity-stop');
        $this->travel(2)->minutes();
        $this->action($student, 'day-finish');

        $report = DailyRoutine::report($student->fresh(), '2026-11-02');
        $this->assertSame(['work' => 2700, 'break' => 600, 'idle' => 420], $report['totals_seconds']);
        $this->assertSame(1500, $report['by_assignment'][0]['seconds']);
        $this->assertSame(1200, $report['by_assignment'][1]['seconds']);
        $this->assertSame('in_progress', $spelling->fresh()->status);
        $this->assertSame('in_progress', $history->fresh()->status);
        $this->assertTrue($firstStart->equalTo($spelling->fresh()->started_at));
        $this->assertNull($report['current']);
        $this->assertNull($student->fresh()->current_assignment_id);
        $this->assertDatabaseCount('work_sessions', 5);
        $this->assertDatabaseHas('school_days', ['finished_at' => '2026-11-02 09:02:00']);
        $this->actingAs($parent)->get('/?date=2026-11-02')->assertOk()->assertSee('The schoolday timeline')->assertSee('History reading')->assertSee('45 min');
    }

    public function test_resume_reopens_the_previous_assignment_without_resetting_its_first_start(): void
    {
        [, $student] = $this->family();
        $assignment = $this->assignment($student, ['status' => 'needs_changes', 'started_at' => now()->subDay()]);
        $firstStart = $assignment->started_at;
        $this->action($student, 'day-start');
        $this->start($student, $assignment);
        $this->travel(10)->minutes();
        $this->action($student, 'break');
        $this->travel(5)->minutes();
        $this->action($student, 'resume');
        $this->travel(5)->minutes();
        $report = DailyRoutine::report($student->fresh(), '2026-11-02');
        $this->assertSame('work', $report['current']['kind']);
        $this->assertSame(900, $report['totals_seconds']['work']);
        $this->assertSame(300, $report['totals_seconds']['break']);
        $this->assertTrue($firstStart->equalTo($assignment->fresh()->started_at));
        $this->assertSame('needs_changes', $assignment->fresh()->status);
        $this->assertFalse($student->fresh()->on_break);
    }

    public function test_stale_device_cannot_change_activity_and_heartbeat_never_changes_reported_time(): void
    {
        [$parent, $student] = $this->family();
        $one = $this->assignment($student);
        $two = $this->assignment($student, ['title' => 'Another activity']);
        $this->action($student, 'day-start');
        $version = $student->fresh()->routine_version;
        $this->start($student, $one);
        $events = Activity::count();
        $periods = WorkSession::count();
        $this->post('/assignments/'.$two->id.'/start', ['version' => $version])->assertConflict();
        $this->post('/routine', ['action' => 'day-finish', 'version' => $version])->assertConflict();
        $this->post('/break', ['on_break' => true, 'version' => $version])->assertConflict();
        $this->assertSame($one->id, $student->fresh()->current_assignment_id);
        $this->postJson('/heartbeat')->assertOk()->assertJsonPath('routine.current.assignment_id', $one->id)->assertJsonPath('routine.version', $version + 1);
        $this->actingAs($parent)->postJson('/heartbeat')->assertOk()->assertJsonPath('routine.current.assignment_id', $one->id);
        $this->assertSame($events, Activity::count());
        $this->assertSame($periods, WorkSession::count());
        $this->assertSame($version + 1, $student->fresh()->routine_version);
    }

    public function test_routine_requires_day_start_and_student_identity_and_rejects_future_or_foreign_work(): void
    {
        [$parent, $student] = $this->family();
        $assignment = $this->assignment($student);
        $this->post('/routine', ['action' => 'day-start', 'version' => 0])->assertRedirect('/login');
        $this->actingAs($parent)->post('/routine', ['action' => 'day-start', 'version' => 0])->assertForbidden();
        $this->get('/routine/finish')->assertForbidden();
        $this->actingAs($student)->post('/assignments/'.$assignment->id.'/start', ['version' => 0])->assertConflict();
        $this->post('/routine', ['action' => 'break', 'version' => 0])->assertConflict();
        $this->post('/routine', ['action' => 'day-start'])->assertSessionHasErrors('version');
        $this->post('/routine', ['action' => 'invented', 'version' => 0])->assertSessionHasErrors('action');
        $this->assertSame(0, $student->fresh()->routine_version);
        $this->action($student, 'day-start');
        $this->post('/routine', ['action' => 'resume', 'version' => 1])->assertConflict();
        $other = User::factory()->create(['role' => 'student']);
        $foreign = $this->assignment($other);
        $future = $this->assignment($student, ['date' => today()->addDay()]);
        $this->post('/assignments/'.$foreign->id.'/start', ['version' => 1])->assertForbidden();
        $this->post('/assignments/'.$future->id.'/start', ['version' => 1])->assertConflict();
        $this->assertSame(1, $student->fresh()->routine_version);
        $this->assertDatabaseCount('work_sessions', 1);
    }

    public function test_submission_closes_only_its_own_work_and_never_approves_unfinished_work(): void
    {
        [, $student] = $this->family();
        $one = $this->assignment($student);
        $two = $this->assignment($student, ['title' => 'Second task']);
        $this->action($student, 'day-start');
        $this->start($student, $one);
        $this->travel(5)->minutes();
        $this->start($student, $two);
        $this->post('/assignments/'.$one->id.'/submit')->assertRedirect();
        $this->assertSame($two->id, DailyRoutine::state($student->fresh())['current']['assignment_id']);
        $this->travel(10)->minutes();
        $this->post('/assignments/'.$two->id.'/submit')->assertRedirect();
        $this->assertSame('idle', DailyRoutine::state($student->fresh())['current']['kind']);
        $this->assertNull($student->fresh()->current_assignment_id);
        $this->get('/routine/finish')->assertOk()->assertSee('2 assignments awaiting Dad’s review');
        $this->action($student, 'day-finish');
        $this->assertSame('submitted', $one->fresh()->status);
        $this->assertNull($one->fresh()->approved_at);
        $this->assertSame(900, DailyRoutine::report($student->fresh(), '2026-11-02')['totals_seconds']['work']);
    }

    public function test_finishing_with_remaining_work_preserves_checklist_and_reopening_keeps_original_day_start(): void
    {
        [, $student] = $this->family();
        $assignment = $this->assignment($student);
        $this->action($student, 'day-start');
        $this->start($student, $assignment);
        $this->travel(30)->minutes();
        $this->get('/routine/finish')->assertOk()->assertSee('Weekly spelling')->assertSee('1 assignment still to finish');
        $this->action($student, 'day-finish');
        $this->travel(30)->minutes();
        $this->action($student, 'day-start');
        $this->assertDatabaseCount('school_days', 1);
        $this->assertSame('08:00:00', SchoolDay::first()->started_at->format('H:i:s'));
        $this->assertSame(1800, DailyRoutine::report($student->fresh(), '2026-11-02')['totals_seconds']['work']);
        $this->assertSame('in_progress', $assignment->fresh()->status);
        $this->assertDatabaseHas('activities', ['description' => 'Reopened the schoolday']);
    }

    public function test_parent_can_correct_and_insert_missed_periods_with_an_immutable_audit_and_no_double_counting(): void
    {
        [$parent, $student] = $this->family();
        $assignment = $this->assignment($student);
        $this->action($student, 'day-start');
        $this->start($student, $assignment);
        $this->travel(60)->minutes();
        $this->action($student, 'day-finish');
        $day = SchoolDay::first();
        $period = WorkSession::where('kind', 'work')->first();
        $before = $period->toArray();
        $url = '/parent/timeline/'.$day->id;
        $data = ['version' => $student->fresh()->routine_version, 'started_at' => '2026-11-02T14:00:00Z', 'ended_at' => '2026-11-02T14:30:00Z', 'reason' => 'Forgot the break at 8:30.'];
        $this->actingAs($parent)->post($url.'/periods/'.$period->id, $data)->assertRedirect();
        $this->assertSame('08:30:00', $period->fresh()->ended_at->format('H:i:s'));
        $this->assertSame($before, TimelineCorrection::first()->before);
        $data = ['version' => $student->fresh()->routine_version, 'kind' => 'break', 'started_at' => '2026-11-02T08:30:00', 'ended_at' => '2026-11-02T09:00:00', 'reason' => 'He took a break.'];
        $this->post($url.'/periods', $data)->assertRedirect();
        $this->assertSame(['work' => 1800, 'break' => 1800, 'idle' => 0], DailyRoutine::report($student->fresh(), '2026-11-02')['totals_seconds']);
        $this->assertDatabaseCount('timeline_corrections', 2);
        $this->post($url.'/periods', $data)->assertConflict();
        $data['version'] = $student->fresh()->routine_version;
        $this->post($url.'/periods', $data)->assertUnprocessable();
        $data['ended_at'] = '2026-11-02T10:00:00';
        $this->post($url.'/periods', $data)->assertUnprocessable();
        $this->assertDatabaseCount('timeline_corrections', 2);
        $this->get('/?date=2026-11-02')->assertOk()->assertSee('Correction history')->assertSee('Forgot the break at 8:30.')->assertSee('Originally');
    }

    public function test_correcting_a_forgotten_day_finish_closes_the_open_period_and_resets_shared_state(): void
    {
        [$parent, $student] = $this->family();
        $assignment = $this->assignment($student);
        $this->action($student, 'day-start');
        $this->start($student, $assignment);
        $this->travel(8)->hours();
        $day = SchoolDay::first();
        $this->actingAs($parent)->post('/parent/timeline/'.$day->id, ['version' => $student->fresh()->routine_version, 'started_at' => '2026-11-02T08:00:00', 'finished_at' => '2026-11-02T10:00:00', 'reason' => 'Forgot to finish after school.'])->assertRedirect();
        $this->assertSame(7200, DailyRoutine::report($student->fresh(), '2026-11-02')['totals_seconds']['work']);
        $this->assertNull(DailyRoutine::openDay($student));
        $this->assertNull($student->fresh()->current_assignment_id);
        $this->assertNull(TimelineCorrection::first()->before['finished_at']);
        $this->assertSame('in_progress', $assignment->fresh()->status);
    }

    public function test_corrections_require_parent_and_matching_day_and_reject_invalid_boundaries_or_future_times(): void
    {
        [$parent, $student] = $this->family();
        $this->action($student, 'day-start');
        $this->travel(1)->hours();
        $day = SchoolDay::first();
        $period = WorkSession::first();
        $data = ['version' => 1, 'started_at' => '2026-11-02T08:00:00', 'ended_at' => '2026-11-02T08:30:00', 'reason' => 'Correction'];
        $url = '/parent/timeline/'.$day->id.'/periods/'.$period->id;
        $this->post($url, $data)->assertForbidden();
        $this->actingAs($parent)->post($url, array_replace($data, ['reason' => '']))->assertSessionHasErrors('reason');
        $this->post($url, array_replace($data, ['ended_at' => '2026-11-02T10:00:00']))->assertUnprocessable();
        $this->post($url, array_replace($data, ['started_at' => '2026-11-02T07:00:00']))->assertUnprocessable();
        $other = User::factory()->create(['role' => 'student']);
        $foreign = SchoolDay::create(['student_id' => $other->id, 'date' => today(), 'started_at' => now()]);
        $this->post('/parent/timeline/'.$foreign->id.'/periods/'.$period->id, $data)->assertNotFound();
        $this->post('/parent/timeline/'.$day->id, ['version' => 1, 'started_at' => '2026-11-02T08:30:00', 'reason' => 'Wrong boundary'])->assertUnprocessable();
        $this->assertDatabaseCount('timeline_corrections', 0);
        $this->assertNull($period->fresh()->ended_at);
        $this->assertSame(1, $student->fresh()->routine_version);
    }

    public function test_cli_timeline_is_read_only_and_corrections_support_dry_run_and_retry_receipts(): void
    {
        [, $student] = $this->family();
        $this->action($student, 'day-start');
        $this->travel(60)->minutes();
        $this->action($student, 'day-finish');
        $day = SchoolDay::first();
        $period = WorkSession::first();
        $count = Activity::count();
        Artisan::call('schoolday', ['action' => 'timeline', '--date' => '2026-11-02', '--compact' => true]);
        $output = json_decode(Artisan::output(), true);
        $this->assertSame(3600, $output['data']['totals_seconds']['idle']);
        $this->assertSame($count, Activity::count());
        $file = tempnam(sys_get_temp_dir(), 'routine-');
        file_put_contents($file, json_encode(['day_id' => $day->id, 'session_id' => $period->id, 'version' => 2, 'started_at' => '2026-11-02T08:00:00', 'ended_at' => '2026-11-02T08:30:00', 'reason' => 'Corrected stop time.']));
        try {
            $arguments = ['action' => 'timeline-correct', '--file' => $file, '--key' => 'correct-one', '--compact' => true];
            $this->assertSame(0, Artisan::call('schoolday', $arguments + ['--dry-run' => true]));
            $this->assertDatabaseCount('timeline_corrections', 0);
            $this->assertSame(2, $student->fresh()->routine_version);
            $this->assertSame(0, Artisan::call('schoolday', $arguments));
            $this->assertSame(0, Artisan::call('schoolday', $arguments));
            $this->assertTrue(json_decode(Artisan::output(), true)['meta']['replayed']);
            $this->assertDatabaseCount('timeline_corrections', 1);
            $this->assertSame(1800, DailyRoutine::report($student->fresh(), '2026-11-02')['totals_seconds']['idle']);
        } finally {
            unlink($file);
        }
    }

    public function test_day_corrections_cannot_overlap_another_day_and_cli_can_correct_day_boundaries(): void
    {
        [$parent, $student] = $this->family();
        $this->action($student, 'day-start');
        $this->travel(1)->hours();
        $this->action($student, 'day-finish');
        $firstDay = SchoolDay::first();
        $this->travel(23)->hours();
        $this->action($student, 'day-start');
        $this->travel(1)->hours();
        $this->action($student, 'day-finish');
        $data = ['version' => $student->fresh()->routine_version, 'started_at' => '2026-11-02T08:00:00', 'finished_at' => '2026-11-03T08:30:00', 'reason' => 'Overlapping days'];
        $this->actingAs($parent)->post('/parent/timeline/'.$firstDay->id, $data)->assertUnprocessable();
        $this->assertSame('2026-11-02 09:00:00', $firstDay->fresh()->finished_at->toDateTimeString());
        $this->assertDatabaseCount('timeline_corrections', 0);
        $file = tempnam(sys_get_temp_dir(), 'day-correction-');
        file_put_contents($file, json_encode(array_replace($data, ['day_id' => $firstDay->id, 'finished_at' => '2026-11-02T09:15:00', 'reason' => 'Finished fifteen minutes later.'])));
        try {
            $this->assertSame(0, Artisan::call('schoolday', ['action' => 'day-correct', '--file' => $file, '--key' => 'day-stop', '--compact' => true]));
            $this->assertSame('09:15:00', $firstDay->fresh()->finished_at->format('H:i:s'));
            $this->assertDatabaseCount('timeline_corrections', 1);
            $this->assertNull(TimelineCorrection::first()->work_session_id);
        } finally {
            unlink($file);
        }
    }

    public function test_old_records_are_not_backfilled_and_a_day_left_open_requires_explicit_finish(): void
    {
        [, $student] = $this->family();
        $this->assignment($student, ['started_at' => now()->subDay(), 'status' => 'in_progress']);
        $this->assertNull(DailyRoutine::report($student, '2026-11-02')['day']);
        $this->action($student, 'day-start');
        $this->travel(1)->days();
        $this->post('/routine', ['action' => 'day-start', 'version' => 1])->assertConflict();
        $this->get('/')->assertOk()->assertSee('This day was left open');
        $this->action($student, 'day-finish');
        $this->action($student, 'day-start');
        $this->assertDatabaseCount('school_days', 2);
        $this->assertSame('2026-11-03', DailyRoutine::openDay($student)->date->toDateString());
    }
}
