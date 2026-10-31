<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Message;
use App\Models\Recurrence;
use App\Models\SchoolDay;
use App\Models\User;
use App\Services\School;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchooldayCliTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function family(): array
    {
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00', 'America/Chicago'));

        return [User::factory()->create(['role' => 'parent', 'email' => 'ryan@example.test']), User::factory()->create(['role' => 'student', 'email' => 'noah@example.test'])];
    }

    private function assignment(User $student, array $overrides = []): Assignment
    {
        return Assignment::create($overrides + ['student_id' => $student->id, 'date' => '2026-11-02', 'subject' => 'Math', 'title' => 'Fractions', 'instructions' => 'Complete lesson 42.', 'requires_photo' => false, 'position' => 0]);
    }

    private function file(array|string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'schoolday-cli-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, is_array($contents) ? json_encode($contents, JSON_THROW_ON_ERROR) : $contents);

        return $path;
    }

    private function runCli(string $action, array $options = [], int $expectedExit = 0): array
    {
        $exit = Artisan::call('schoolday', ['action' => $action, '--compact' => true] + $options);
        $output = Artisan::output();
        $this->assertSame($expectedExit, $exit, $output);
        $this->assertSame(1, count(explode("\n", trim($output))), 'Compact output must be a single JSON line.');

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_help_and_schemas_work_without_accounts_and_bad_actions_return_json(): void
    {
        $result = $this->runCli('help');
        $this->assertSame(1, $result['meta']['schema_version']);
        $this->assertArrayHasKey('plan --file=plan.json --dry-run', $result['data']['commands']);
        $schema = $this->runCli('schema', ['target' => 'plan']);
        $this->assertSame(['date', 'assignments'], $schema['data']['required']);
        $error = $this->runCli('bogus', [], 2);
        $this->assertSame('validation_error', $error['error']['code']);
    }

    public function test_status_is_read_only_and_distinguishes_contact_from_work(): void
    {
        [$dad, $noah] = $this->family();
        $current = $this->assignment($noah, ['status' => 'in_progress', 'started_at' => now()->subMinutes(10)]);
        $submitted = $this->assignment($noah, ['status' => 'submitted', 'revision' => 1, 'submitted_at' => now()]);
        $older = $this->assignment($noah, ['date' => '2026-10-30']);
        $noah->update(['current_assignment_id' => $current->id, 'last_seen_at' => now()->subMinutes(2), 'on_break' => true]);
        $day = SchoolDay::create(['student_id' => $noah->id, 'date' => today(), 'started_at' => now()->subMinutes(10)]);
        $day->sessions()->create(['kind' => 'work', 'label' => 'Math · Fractions', 'assignment_id' => $current->id, 'started_at' => now()->subMinutes(10), 'ended_at' => now()->subMinutes(5)]);
        $day->sessions()->create(['kind' => 'break', 'label' => 'Break', 'started_at' => now()->subMinutes(5)]);
        Message::create(['sender_id' => $noah->id, 'student_id' => $noah->id, 'body' => 'Help', 'help' => true]);
        Recurrence::create(['student_id' => $noah->id, 'starts_on' => '2026-11-02', 'weekdays' => [1], 'assignment_data' => ['subject' => 'Spelling', 'title' => 'Words', 'instructions' => 'Practice.']]);

        $result = $this->runCli('status');

        $this->assertNull($result['data']['current_assignment']);
        $this->assertSame('break', $result['data']['routine']['current']['kind']);
        $this->assertSame(['work' => 300, 'break' => 300, 'idle' => 0], $result['data']['routine']['totals_seconds']);
        $this->assertSame(600, $result['data']['assignments'][0]['elapsed_seconds_including_breaks']);
        $this->assertSame(false, $result['data']['tablet']['recent_contact']);
        $this->assertSame(120, $result['data']['tablet']['seconds_since_contact']);
        $this->assertSame(1, $result['data']['unread_help_requests']);
        $this->assertSame($submitted->id, $result['data']['awaiting_review'][0]['id']);
        $this->assertSame($older->id, $result['data']['earlier_unfinished'][0]['id']);
        $this->assertDatabaseCount('assignments', 3);
        $this->assertDatabaseCount('activities', 0);
        $this->assertNull(Message::first()->read_at);
        $this->assertSame(now()->subMinutes(2)->toIso8601String(), $noah->fresh()->last_seen_at->toIso8601String());
    }

    public static function studentForbiddenActions(): array
    {
        return [['status'], ['plan'], ['review'], ['message'], ['recurring-stop']];
    }

    #[DataProvider('studentForbiddenActions')]
    public function test_student_cannot_be_selected_as_a_cli_actor(string $action): void
    {
        [$dad, $noah] = $this->family();

        $result = $this->runCli($action, ['--as' => $noah->email], 4);

        $this->assertSame('forbidden', $result['error']['code']);
        $this->assertDatabaseCount('activities', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_ambiguous_parent_requires_explicit_identity(): void
    {
        [$dad, $noah] = $this->family();
        User::factory()->create(['role' => 'parent']);

        $error = $this->runCli('status', [], 5);
        $selected = $this->runCli('status', ['--as' => $dad->email]);

        $this->assertSame('conflict', $error['error']['code']);
        $this->assertSame($dad->id, $selected['meta']['actor_id']);
    }

    public function test_bulk_plan_dry_run_rolls_back_and_retries_do_not_duplicate_work(): void
    {
        [$dad, $noah] = $this->family();
        $file = $this->file(['date' => '2026-11-03', 'assignments' => [
            ['subject' => 'Math', 'title' => 'Fractions', 'instructions' => 'Complete lesson 43.'],
            ['subject' => 'Writing', 'title' => 'Paragraph', 'instructions' => 'Write a paragraph.', 'requires_photo' => true],
        ]]);

        $preview = $this->runCli('plan', ['--file' => $file, '--dry-run' => true, '--key' => 'tuesday-plan']);

        $this->assertSame(true, $preview['meta']['dry_run']);
        $this->assertDatabaseCount('assignments', 0);
        $this->assertDatabaseCount('activities', 0);
        $this->assertDatabaseCount('cli_operations', 0);

        $created = $this->runCli('plan', ['--file' => $file, '--key' => 'tuesday-plan']);
        $retry = $this->runCli('plan', ['--file' => $file, '--key' => 'tuesday-plan']);

        $this->assertSame($created['data'], $retry['data']);
        $this->assertSame(true, $retry['meta']['replayed']);
        $this->assertSame([0, 1], array_column($created['data']['assignments'], 'position'));
        $this->assertDatabaseCount('assignments', 2);
        $this->assertDatabaseCount('activities', 2);
        $this->assertDatabaseCount('cli_operations', 1);
        $this->assertDatabaseHas('assignments', ['student_id' => $noah->id, 'title' => 'Paragraph', 'requires_photo' => true]);
        $this->actingAs($dad)->get('/?date=2026-11-03')->assertOk()->assertSee('Paragraph');
    }

    public function test_bulk_failure_rolls_back_an_earlier_valid_assignment_and_retry_receipt(): void
    {
        [$dad, $noah] = $this->family();
        $this->assignment($noah, ['position' => 998]);
        $file = $this->file(['date' => '2026-11-02', 'assignments' => [
            ['subject' => 'Math', 'title' => 'First', 'instructions' => 'Practice.'],
            ['subject' => 'Math', 'title' => 'Second', 'instructions' => 'Practice.'],
        ]]);

        $result = $this->runCli('plan', ['--file' => $file, '--key' => 'overflow'], 2);

        $this->assertSame('validation_error', $result['error']['code']);
        $this->assertDatabaseCount('assignments', 1);
        $this->assertDatabaseCount('activities', 0);
        $this->assertDatabaseCount('cli_operations', 0);
    }

    public function test_unknown_assignment_fields_are_rejected_and_started_work_cannot_be_edited(): void
    {
        [$dad, $noah] = $this->family();
        $assignment = $this->assignment($noah);
        $unsafe = $this->file(['status' => 'approved', 'student_id' => $dad->id]);

        $this->runCli('update', ['target' => $assignment->id, '--file' => $unsafe], 2);

        $this->assertSame('not_started', $assignment->fresh()->status);
        $this->assertSame($noah->id, $assignment->fresh()->student_id);
        $patch = $this->file(['title' => 'Updated fractions', 'date' => '2026-11-03']);
        $this->runCli('update', ['target' => $assignment->id, '--file' => $patch, '--key' => 'edit-1']);
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id, 'title' => 'Updated fractions', 'date' => '2026-11-03']);
        $assignment->update(['status' => 'in_progress', 'started_at' => now()]);

        $this->runCli('update', ['target' => $assignment->id, '--file' => $patch], 5);

        $this->assertDatabaseCount('activities', 1);
    }

    public function test_review_requires_current_revision_and_corrections_require_feedback(): void
    {
        [$dad, $noah] = $this->family();
        $assignment = $this->assignment($noah, ['status' => 'submitted', 'revision' => 2]);
        $options = ['target' => $assignment->id, '--decision' => 'needs_changes'];

        $this->runCli('review', $options, 2);
        $this->runCli('review', $options + ['--revision' => 1, '--feedback' => 'Fix the ending.'], 5);
        $this->runCli('review', $options + ['--revision' => 2, '--feedback' => '   '], 2);

        $this->assertSame('submitted', $assignment->fresh()->status);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('activities', 0);

        $this->runCli('review', $options + ['--revision' => 2, '--feedback' => 'Fix the ending.', '--key' => 'review-corrections']);

        $this->assertDatabaseHas('assignments', ['id' => $assignment->id, 'status' => 'needs_changes', 'feedback' => 'Fix the ending.']);
        $this->assertDatabaseHas('messages', ['sender_id' => $dad->id, 'assignment_id' => $assignment->id, 'body' => 'Please make corrections. Fix the ending.']);
        $this->actingAs($noah)->post('/assignments/'.$assignment->id.'/submit', ['note' => 'Fixed.'])->assertRedirect();
        $this->assertSame(3, $assignment->fresh()->revision);
        $this->runCli('review', ['target' => $assignment->id, '--decision' => 'approved', '--revision' => 2], 5);
        $this->assertSame('submitted', $assignment->fresh()->status);
    }

    public function test_approval_dry_run_and_retry_send_exactly_one_feedback_message(): void
    {
        [$dad, $noah] = $this->family();
        $assignment = $this->assignment($noah, ['status' => 'submitted', 'revision' => 1]);
        $options = ['target' => $assignment->id, '--decision' => 'approved', '--revision' => 1, '--feedback-file' => $this->file("Good work.\nClear steps."), '--key' => 'approve-once'];

        $this->runCli('review', $options + ['--dry-run' => true]);

        $this->assertSame('submitted', $assignment->fresh()->status);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('cli_operations', 0);
        $first = $this->runCli('review', $options);
        $retry = $this->runCli('review', $options);
        $this->assertSame($first['data'], $retry['data']);
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id, 'status' => 'approved']);
        $this->assertNotNull($assignment->fresh()->approved_at);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('activities', 1);
        $this->runCli('review', array_replace($options, ['--feedback-file' => $this->file('Changed feedback')]), 5);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_message_cursors_do_not_change_unread_state_and_read_has_an_explicit_boundary(): void
    {
        [$dad, $noah] = $this->family();
        $first = Message::create(['sender_id' => $noah->id, 'student_id' => $noah->id, 'body' => 'First', 'help' => true]);
        $second = Message::create(['sender_id' => $noah->id, 'student_id' => $noah->id, 'body' => 'Second']);
        $third = Message::create(['sender_id' => $dad->id, 'student_id' => $noah->id, 'body' => 'Reply']);

        $page = $this->runCli('messages', ['--after' => 0, '--limit' => 2]);
        $next = $this->runCli('messages', ['--after' => $page['data']['next_after'], '--limit' => 2]);
        $unread = $this->runCli('messages', ['--unread' => true]);

        $this->assertSame([$first->id, $second->id], array_column($page['data']['messages'], 'id'));
        $this->assertSame(true, $page['data']['has_more']);
        $this->assertSame([$third->id], array_column($next['data']['messages'], 'id'));
        $this->assertSame(2, count($unread['data']['messages']));
        $this->assertNull($first->fresh()->read_at);
        $this->runCli('read', ['--through' => $first->id, '--dry-run' => true]);
        $this->assertNull($first->fresh()->read_at);
        $this->runCli('read', ['--through' => $first->id]);
        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($second->fresh()->read_at);
        $this->runCli('read', ['--through' => 99999], 3);
        $this->assertNull($second->fresh()->read_at);
    }

    public function test_multiline_messages_preserve_literal_text_and_retries_do_not_send_twice(): void
    {
        [$dad, $noah] = $this->family();
        $assignment = $this->assignment($noah);
        $body = "Nice effort!\n<error>Keep going</error> · literal: ".'$(uname) `whoami`';
        $options = ['--file' => $this->file($body), '--assignment' => $assignment->id, '--key' => 'message-once'];

        $preview = $this->runCli('message', $options + ['--dry-run' => true]);

        $this->assertSame($body, $preview['data']['message']['body']);
        $this->assertDatabaseCount('messages', 0);
        $first = $this->runCli('message', $options);
        $retry = $this->runCli('message', $options);
        $this->assertSame($first['data'], $retry['data']);
        $this->assertDatabaseHas('messages', ['sender_id' => $dad->id, 'student_id' => $noah->id, 'assignment_id' => $assignment->id, 'body' => $body]);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('activities', 1);
        $this->runCli('message', $options + ['--body' => 'Conflicting input'], 2);
        $this->runCli('message', array_replace($options, ['--file' => $this->file('Changed input')]), 5);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_copy_resets_work_and_reorder_requires_the_complete_date(): void
    {
        [$dad, $noah] = $this->family();
        $first = $this->assignment($noah, ['status' => 'approved', 'revision' => 1, 'note' => 'Completed', 'position' => 0]);
        $second = $this->assignment($noah, ['position' => 1]);
        $options = ['--date' => '2026-11-02', '--to' => 'tomorrow', '--key' => 'copy-once'];

        $copy = $this->runCli('copy', $options);
        $this->runCli('copy', $options);

        $this->assertDatabaseCount('assignments', 4);
        $newIds = array_column($copy['data']['assignments'], 'id');
        $this->assertSame('not_started', $copy['data']['assignments'][0]['status']);
        $this->assertSame(0, $copy['data']['assignments'][0]['revision']);
        $this->assertNull($copy['data']['assignments'][0]['note']);
        $this->runCli('reorder', ['--date' => 'tomorrow', '--ids' => implode(',', array_reverse($newIds)), '--dry-run' => true]);
        $this->assertSame(0, Assignment::findOrFail($newIds[0])->position);
        $this->runCli('reorder', ['--date' => 'tomorrow', '--ids' => implode(',', array_reverse($newIds))]);
        $this->assertSame(1, Assignment::findOrFail($newIds[0])->position);
        $this->runCli('reorder', ['--date' => 'tomorrow', '--ids' => (string) $first->id], 2);
        $this->assertSame(0, $first->fresh()->position);
    }

    public function test_recurrence_creation_dry_run_retry_and_stop_preserve_existing_work(): void
    {
        [$dad, $noah] = $this->family();
        $file = $this->file(['date' => '2026-11-02', 'subject' => 'Spelling', 'title' => 'Word practice', 'instructions' => 'Practice words.', 'weekdays' => [1, 2, 3, 4, 5], 'ends_on' => '2026-11-06']);

        $this->runCli('recurring-add', ['--file' => $file, '--dry-run' => true, '--key' => 'words']);

        $this->assertDatabaseCount('recurrences', 0);
        $this->assertDatabaseCount('assignments', 0);
        $created = $this->runCli('recurring-add', ['--file' => $file, '--key' => 'words']);
        $this->runCli('recurring-add', ['--file' => $file, '--key' => 'words']);
        School::generate('2026-11-02');
        $this->assertDatabaseCount('recurrences', 1);
        $this->assertDatabaseCount('assignments', 1);
        $this->runCli('recurring-stop', ['target' => $created['data']['recurrence']['id']]);
        School::generate('2026-11-03');
        $this->assertDatabaseCount('assignments', 1);
        $rules = $this->runCli('recurring');
        $this->assertSame(false, $rules['data']['recurrences'][0]['active']);
    }

    public function test_assignment_details_expose_private_photo_paths_without_making_photos_public(): void
    {
        [$dad, $noah] = $this->family();
        Storage::fake('local');
        $assignment = $this->assignment($noah);
        Storage::disk('local')->put('worksheets/test.jpg', 'private content');
        $photo = $assignment->photos()->create(['path' => 'worksheets/test.jpg', 'name' => 'Worksheet.jpg', 'revision' => 1]);

        $result = $this->runCli('assignment', ['target' => $assignment->id]);

        $this->assertSame(Storage::disk('local')->path('worksheets/test.jpg'), $result['data']['photos'][0]['local_path']);
        $this->assertSame(true, $result['data']['photos'][0]['exists']);
        $this->get('/photos/'.$photo->id)->assertRedirect('/login');
        $this->runCli('assignment', ['target' => 99999], 3);
    }

    public function test_invalid_json_dates_limits_and_irrelevant_options_fail_without_writes(): void
    {
        [$dad, $noah] = $this->family();

        $this->runCli('plan', ['--file' => $this->file('{oops')], 2);
        $this->runCli('plan', ['--file' => $this->file('[]')], 2);
        $this->runCli('plan', ['--file' => '/does/not/exist.json'], 2);
        $this->runCli('status', ['--date' => '2026-02-30'], 2);
        $this->runCli('messages', ['--limit' => 201], 2);
        $this->runCli('status', ['--body' => 'Ignored option'], 2);
        $this->runCli('assignments', ['--status' => 'invalid'], 2);

        $this->assertDatabaseCount('assignments', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_activity_cursor_reports_parent_actions_without_updating_tablet_contact(): void
    {
        [$dad, $noah] = $this->family();
        $this->runCli('message', ['--body' => 'Start with math.']);
        $this->runCli('message', ['--body' => 'Let me know when you are ready.']);

        $first = $this->runCli('activity', ['--after' => 0, '--limit' => 1]);
        $second = $this->runCli('activity', ['--after' => $first['data']['next_after'], '--limit' => 1]);

        $this->assertSame(true, $first['data']['has_more']);
        $this->assertSame(false, $second['data']['has_more']);
        $this->assertSame('message', $first['data']['activity'][0]['kind']);
        $this->assertSame($dad->id, $first['data']['activity'][0]['user_id']);
        $this->assertNull($noah->fresh()->last_seen_at);
    }
}
