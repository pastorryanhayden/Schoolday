<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\DeviceToken;
use App\Models\Game;
use App\Models\Message;
use App\Models\Recurrence;
use App\Models\User;
use App\Services\ParentActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentApiTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/student';

    private function login(User $student, string $device = 'Noah iPhone'): string
    {
        return $this->postJson(self::API.'/login', ['email' => $student->email, 'password' => 'password', 'device_name' => $device])->assertCreated()->json('data.token');
    }

    private function work(User $student, array $data = []): Assignment
    {
        return Assignment::create($data + ['student_id' => $student->id, 'date' => today()->toDateString(), 'title' => 'Write a paragraph', 'subject' => 'Writing', 'instructions' => 'Write about your day.', 'requires_photo' => false]);
    }

    private function version(User $student): int
    {
        return $student->fresh()->routine_version;
    }

    public function test_login_returns_only_a_plaintext_device_token_once_and_authenticates_without_cookies(): void
    {
        $this->freezeTime();
        $student = User::factory()->create(['role' => 'student']);
        $token = $this->login($student);
        $stored = DeviceToken::firstOrFail();
        $this->assertNotSame($token, $stored->token_hash);
        $this->assertSame(hash('sha256', $token), $stored->token_hash);

        $this->withToken($token)->get(self::API.'/me')->assertOk()->assertExactJson(['data' => $student->only(['id', 'name', 'email', 'role'])])->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(self::API.'/devices')->assertOk()->assertJsonPath('data.0.current', true)->assertJsonMissingPath('data.0.token_hash')->assertJsonMissingPath('data.0.password_fingerprint');
        $this->withToken($token)->get('/')->assertRedirect('/login');
    }

    public function test_invalid_parent_and_missing_login_credentials_do_not_issue_tokens_and_are_rate_limited(): void
    {
        $parent = User::factory()->create(['role' => 'parent']);
        $this->postJson(self::API.'/login')->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'device_name']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(self::API.'/login', ['email' => $parent->email, 'password' => 'password', 'device_name' => 'iPhone'])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->postJson(self::API.'/login', ['email' => $parent->email, 'password' => 'wrong', 'device_name' => 'iPhone'])->assertTooManyRequests();
        $this->assertDatabaseCount('device_tokens', 0);
    }

    public function test_every_protected_route_requires_a_bearer_token_even_with_a_web_session(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $work = $this->work($student);
        $game = Game::factory()->create(['student_id' => $student->id]);
        $photo = $work->photos()->create(['path' => 'private.jpg', 'name' => 'Worksheet']);
        $device = DeviceToken::factory()->create(['user_id' => $student->id]);
        $this->actingAs($student);
        $requests = [
            ['GET', '/me'], ['POST', '/logout'], ['POST', '/logout-all'], ['GET', '/devices'], ['DELETE', '/devices/'.$device->id],
            ['GET', '/day'], ['GET', '/routine'], ['POST', '/routine'], ['GET', '/routine/finish'], ['POST', '/heartbeat'],
            ['GET', '/assignments'], ['GET', '/assignments/'.$work->id], ['POST', '/assignments/'.$work->id.'/start'], ['PATCH', '/assignments/'.$work->id.'/draft'], ['POST', '/assignments/'.$work->id.'/submit'], ['POST', '/assignments/'.$work->id.'/photos'],
            ['GET', '/photos/'.$photo->id], ['DELETE', '/photos/'.$photo->id], ['GET', '/messages'], ['POST', '/messages'], ['POST', '/messages/read'], ['GET', '/games'], ['POST', '/games/'.$game->id.'/play'], ['GET', '/practice/'.$work->id], ['POST', '/practice/'.$work->id],
        ];
        foreach ($requests as [$method, $path]) {
            $this->json($method, self::API.$path)->assertUnauthorized()->assertJsonStructure(['message']);
        }
        $this->get(self::API.'/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('work_sessions', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_parent_device_tokens_and_invalid_tokens_are_refused(): void
    {
        $parent = User::factory()->create(['role' => 'parent']);
        $plain = str_repeat('p', 64);
        DeviceToken::factory()->create(['user_id' => $parent->id, 'token_hash' => hash('sha256', $plain)]);
        $this->withToken($plain)->getJson(self::API.'/day')->assertForbidden();
        $this->withToken(str_repeat('x', 64))->getJson(self::API.'/day')->assertUnauthorized();
        $this->withToken('invalid')->getJson(self::API.'/day')->assertUnauthorized();
    }

    public function test_devices_can_be_revoked_individually_logged_out_or_all_logged_out(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $first = $this->login($student);
        $second = $this->login($student, 'iPad');
        $secondId = DeviceToken::where('token_hash', hash('sha256', $second))->value('id');
        $this->withToken($first)->deleteJson(self::API.'/devices/'.$secondId)->assertOk()->assertJsonPath('data.revoked', true);
        $this->withToken($second)->getJson(self::API.'/me')->assertUnauthorized();
        $this->withToken($first)->postJson(self::API.'/logout')->assertOk();
        $this->getJson(self::API.'/me')->assertUnauthorized();
        $third = $this->login($student);
        $fourth = $this->login($student, 'iPad');
        $this->withToken($third)->postJson(self::API.'/logout-all')->assertOk();
        $this->withToken($fourth)->getJson(self::API.'/me')->assertUnauthorized();
        $this->assertDatabaseCount('device_tokens', 0);
    }

    public function test_expiry_and_password_changes_revoke_device_access(): void
    {
        $this->freezeTime();
        $student = User::factory()->create(['role' => 'student']);
        $token = $this->login($student);
        $this->travel(30)->days();
        $this->withToken($token)->getJson(self::API.'/me')->assertUnauthorized();
        $token = $this->login($student);
        $student->update(['password' => 'changed-password']);
        $this->withToken($token)->getJson(self::API.'/me')->assertUnauthorized();
    }

    public function test_foreign_assignments_photos_games_and_devices_are_hidden_on_every_action(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);
        $work = $this->work($other);
        $photo = $work->photos()->create(['path' => 'secret.jpg', 'name' => 'Secret']);
        $game = Game::factory()->create(['student_id' => $other->id]);
        $device = DeviceToken::factory()->create(['user_id' => $other->id]);
        $this->withToken($this->login($student));
        foreach ([['GET', '/assignments/'.$work->id], ['POST', '/assignments/'.$work->id.'/start'], ['PATCH', '/assignments/'.$work->id.'/draft'], ['POST', '/assignments/'.$work->id.'/submit'], ['POST', '/assignments/'.$work->id.'/photos'], ['GET', '/photos/'.$photo->id], ['DELETE', '/photos/'.$photo->id], ['POST', '/games/'.$game->id.'/play'], ['GET', '/practice/'.$work->id], ['POST', '/practice/'.$work->id], ['DELETE', '/devices/'.$device->id]] as [$method, $path]) {
            $this->json($method, self::API.$path)->assertNotFound();
        }
        $this->postJson(self::API.'/messages', ['body' => 'Help', 'assignment_id' => $work->id])->assertForbidden();
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('work_sessions', 0);
        $this->assertModelExists($photo);
    }

    public function test_day_generates_recurring_work_and_keeps_required_progress_separate_from_extra_rounds(): void
    {
        $this->freezeTime();
        $student = User::factory()->create(['role' => 'student']);
        $parent = User::factory()->create(['role' => 'parent']);
        Recurrence::create(['student_id' => $student->id, 'starts_on' => today(), 'weekdays' => [today()->dayOfWeekIso], 'assignment_data' => ['title' => 'Daily reading', 'subject' => 'Reading', 'instructions' => 'Read.']]);
        $this->work($student, ['date' => today()->subDay(), 'status' => 'needs_changes']);
        $this->work($student, ['status' => 'submitted']);
        $this->work($student, ['is_extra_practice' => true, 'status' => 'submitted']);
        $message = Message::create(['student_id' => $student->id, 'sender_id' => $parent->id, 'body' => 'Hello']);
        $this->withToken($this->login($student));

        $this->getJson(self::API.'/day')->assertOk()->assertJsonCount(2, 'data.assignments')->assertJsonPath('data.progress.total', 2)->assertJsonPath('data.progress.submitted', 1)->assertJsonCount(1, 'data.unfinished')->assertJsonCount(1, 'data.awaiting_review')->assertJsonPath('data.unread', 1);
        $this->getJson(self::API.'/day')->assertOk();
        $this->getJson(self::API.'/assignments?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', true)->assertJsonPath('meta.next_page', 2);
        $this->getJson(self::API.'/assignments?extra=1')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('assignments', 4);
        $this->assertNull($message->fresh()->read_at);
        $this->assertDatabaseCount('school_days', 0);
    }

    public function test_routine_supports_start_switch_stop_break_resume_finish_reopen_and_rejects_stale_devices(): void
    {
        $this->freezeTime();
        $student = User::factory()->create(['role' => 'student']);
        $first = $this->work($student);
        $second = $this->work($student);
        $this->withToken($this->login($student));
        $this->postJson(self::API.'/routine', ['action' => 'day-start', 'version' => 0])->assertOk()->assertJsonPath('data.current.kind', 'idle');
        $this->postJson(self::API.'/assignments/'.$first->id.'/start', ['version' => 0])->assertConflict();
        $this->postJson(self::API.'/assignments/'.$first->id.'/start', ['version' => $this->version($student)])->assertOk()->assertJsonPath('data.assignment.status', 'in_progress');
        $this->travel(3)->minutes();
        $this->postJson(self::API.'/routine', ['action' => 'break', 'version' => $this->version($student)])->assertOk()->assertJsonPath('data.current.kind', 'break');
        $this->travel(1)->minutes();
        $this->postJson(self::API.'/routine', ['action' => 'resume', 'version' => $this->version($student)])->assertOk()->assertJsonPath('data.current.assignment_id', $first->id);
        $this->postJson(self::API.'/assignments/'.$second->id.'/start', ['version' => $this->version($student)])->assertOk();
        $this->postJson(self::API.'/routine', ['action' => 'activity-stop', 'version' => $this->version($student)])->assertOk()->assertJsonPath('data.current.kind', 'idle');
        $this->getJson(self::API.'/routine')->assertOk()->assertJsonPath('data.totals_seconds.work', 180)->assertJsonPath('data.totals_seconds.break', 60);
        $this->getJson(self::API.'/routine/finish')->assertOk()->assertJsonCount(2, 'data.remaining')->assertJsonPath('data.awaiting_review_count', 0);
        $this->postJson(self::API.'/routine', ['action' => 'day-finish', 'version' => $this->version($student)])->assertOk()->assertJsonPath('data.day', null);
        $this->postJson(self::API.'/routine', ['action' => 'day-start', 'version' => $this->version($student)])->assertOk();
        $this->assertDatabaseCount('school_days', 1);
        $this->assertDatabaseHas('assignments', ['id' => $first->id, 'status' => 'in_progress']);
    }

    public function test_heartbeat_does_not_start_work_or_count_extra_practice_as_required_submissions(): void
    {
        $this->freezeTime();
        $student = User::factory()->create(['role' => 'student']);
        $this->work($student, ['is_extra_practice' => true, 'status' => 'submitted']);
        $this->withToken($this->login($student))->postJson(self::API.'/heartbeat')->assertOk()->assertJsonPath('data.submitted', 0)->assertJsonPath('data.routine.version', 0)->assertJsonPath('data.routine.day', null);
        $this->assertNotNull($student->fresh()->last_seen_at);
        $this->assertDatabaseCount('activities', 0);
        $this->assertDatabaseCount('work_sessions', 0);
    }

    public function test_assignment_photo_submission_corrections_and_resubmission_preserve_evidence(): void
    {
        Storage::fake('local');
        $student = User::factory()->create(['role' => 'student']);
        $parent = User::factory()->create(['role' => 'parent']);
        $work = $this->work($student, ['requires_photo' => true]);
        $url = self::API.'/assignments/'.$work->id;
        $this->withToken($this->login($student));
        $this->patchJson($url.'/draft', ['note' => 'My first draft', 'status' => 'approved', 'student_id' => $parent->id])->assertOk()->assertJsonPath('data.assignment.note', 'My first draft')->assertJsonPath('data.assignment.status', 'not_started');
        $this->postJson($url.'/submit')->assertUnprocessable()->assertJsonValidationErrors('photo');
        $photo = $this->post($url.'/photos', ['photo' => UploadedFile::fake()->image('worksheet.jpg')])->assertCreated()->assertJsonMissingPath('data.path')->json('data');
        $path = $work->photos()->firstOrFail()->path;
        Storage::disk('local')->assertExists($path);
        $this->get($photo['url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($url)->assertOk()->assertJsonPath('data.photos.0.id', $photo['id'])->assertJsonMissingPath('data.photos.0.path');
        $this->postJson($url.'/submit', ['note' => 'All done'])->assertOk()->assertJsonPath('data.assignment.status', 'submitted')->assertJsonPath('data.assignment.revision', 1);
        $this->postJson($url.'/submit')->assertConflict();
        $this->patchJson($url.'/draft', ['note' => 'Changed'])->assertConflict();
        ParentActions::review($parent, $work->id, 'needs_changes', 'Fix line two.', 1);
        $this->getJson($url)->assertOk()->assertJsonPath('data.feedback', 'Fix line two.');
        $this->deleteJson($photo['url'])->assertConflict();
        $draft = $this->post($url.'/photos', ['photo' => UploadedFile::fake()->image('retry.jpg')])->assertCreated()->json('data');
        $draftPath = $work->photos()->findOrFail($draft['id'])->path;
        $this->deleteJson($draft['url'])->assertOk();
        Storage::disk('local')->assertMissing($draftPath);
        $this->postJson($url.'/submit')->assertOk()->assertJsonPath('data.assignment.revision', 2);
        $this->assertDatabaseHas('photos', ['id' => $photo['id'], 'revision' => 1]);
        Storage::disk('local')->assertExists($path);
    }

    public function test_invalid_payloads_and_unsafe_or_excess_photos_do_not_change_work(): void
    {
        Storage::fake('local');
        $student = User::factory()->create(['role' => 'student']);
        $work = $this->work($student);
        $url = self::API.'/assignments/'.$work->id;
        $this->withToken($this->login($student));
        $this->getJson(self::API.'/day?date=bad')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson(self::API.'/assignments?limit=101')->assertUnprocessable();
        $this->postJson(self::API.'/routine', ['action' => 'day-start'])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson(self::API.'/routine', ['action' => 'approve', 'version' => 0])->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->patchJson($url.'/draft', [])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->patchJson($url.'/draft', ['note' => str_repeat('x', 4001)])->assertUnprocessable();
        $this->post($url.'/photos', ['photo' => UploadedFile::fake()->create('script.svg', 1, 'image/svg+xml')])->assertUnprocessable();
        $this->post($url.'/photos', ['photo' => [UploadedFile::fake()->image('array.jpg')]])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->post($url.'/photos', ['photo' => UploadedFile::fake()->image('big.jpg')->size(10241)])->assertUnprocessable();
        for ($index = 0; $index < 20; $index++) {
            $work->photos()->create(['path' => 'photo-'.$index, 'name' => 'Worksheet']);
        }
        $this->post($url.'/photos', ['photo' => UploadedFile::fake()->image('extra.jpg')])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('assignments', ['id' => $work->id, 'status' => 'not_started', 'note' => null]);
    }

    public function test_messages_support_help_context_pagination_and_explicit_read_acknowledgment(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $parent = User::factory()->create(['role' => 'parent']);
        $work = $this->work($student);
        $incoming = Message::create(['student_id' => $student->id, 'sender_id' => $parent->id, 'body' => 'Hello']);
        $newer = Message::create(['student_id' => $student->id, 'sender_id' => $parent->id, 'body' => 'Newer']);
        $this->withToken($this->login($student));
        $this->getJson(self::API.'/messages?limit=1')->assertOk()->assertJsonPath('data.0.id', $newer->id)->assertJsonPath('meta.has_more', true);
        $this->getJson(self::API.'/messages?before='.$newer->id)->assertOk()->assertJsonPath('data.0.id', $incoming->id);
        $this->getJson(self::API.'/messages?after='.$incoming->id)->assertOk()->assertJsonPath('data.0.id', $newer->id);
        $this->assertNull($incoming->fresh()->read_at);
        $sent = $this->postJson(self::API.'/messages', ['body' => 'I need help', 'help' => true, 'assignment_id' => $work->id, 'sender_id' => $parent->id])->assertCreated()->assertJsonPath('data.is_mine', true)->assertJsonPath('data.sender.id', $student->id)->json('data.id');
        $this->assertDatabaseHas('messages', ['id' => $sent, 'help' => true, 'assignment_id' => $work->id, 'sender_id' => $student->id]);
        $this->postJson(self::API.'/messages/read', ['through' => $incoming->id])->assertOk()->assertJsonPath('data.marked_read', 1);
        $this->assertNotNull($incoming->fresh()->read_at);
        $this->assertNull($newer->fresh()->read_at);
        $this->getJson(self::API.'/messages')->assertOk()->assertJsonPath('meta.unread', 1);
        $plain = $this->postJson(self::API.'/messages', ['body' => 'Thank you', 'help' => null])->assertCreated()->assertJsonPath('data.help', false)->json('data.id');
        $this->assertDatabaseHas('messages', ['id' => $plain, 'help' => false]);
        $this->postJson(self::API.'/messages', ['body' => ''])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->getJson(self::API.'/messages?before=2&after=1')->assertUnprocessable();
    }

    public function test_foreign_messages_cannot_be_read_or_acknowledged(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);
        $message = Message::create(['student_id' => $other->id, 'sender_id' => $other->id, 'body' => 'Private']);
        $this->withToken($this->login($student));
        $this->getJson(self::API.'/messages')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::API.'/messages/read', ['through' => $message->id])->assertNotFound();
        $this->assertNull($message->fresh()->read_at);
    }

    public function test_game_launch_and_multiplication_actions_preserve_targets_retries_and_extra_practice_rules(): void
    {
        $this->freezeTime();
        $student = User::factory()->create(['role' => 'student']);
        $game = Game::factory()->create(['student_id' => $student->id, 'configuration' => ['activity_type' => 'multiplication', 'title' => 'Two times two', 'minutes' => 5, 'fact_min' => 2, 'fact_max' => 2, 'question_target' => 2, 'requires_photo' => false]]);
        $this->withToken($this->login($student));
        $this->getJson(self::API.'/games')->assertOk()->assertJsonPath('data.0.id', $game->id);
        $this->postJson(self::API.'/games/'.$game->id.'/play', ['version' => 0])->assertConflict();
        $this->assertDatabaseCount('assignments', 0);
        $this->postJson(self::API.'/routine', ['action' => 'day-start', 'version' => 0])->assertOk();
        $version = $this->version($student);
        $round = $this->postJson(self::API.'/games/'.$game->id.'/play', ['version' => $version])->assertCreated()->json('data.assignment.id');
        $this->postJson(self::API.'/games/'.$game->id.'/play', ['version' => $version])->assertConflict();
        $url = self::API.'/practice/'.$round;
        $this->postJson(self::API.'/assignments/'.$round.'/submit')->assertConflict();
        $prompt = $this->postJson($url, ['action' => 'start', 'version' => $this->version($student)])->assertOk()->json('data.question.id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.practice.question.id', $prompt);
        $this->postJson($url, ['action' => 'help', 'version' => $this->version($student), 'attempt_id' => $prompt])->assertOk();
        $this->travel(2)->seconds();
        $answer = ['action' => 'answer', 'version' => $this->version($student), 'attempt_id' => $prompt, 'answer' => 4, 'response_ms' => 2000];
        $this->postJson($url, $answer)->assertOk()->assertJsonPath('data.result.correct', true)->assertJsonPath('data.result.assisted', true);
        $this->postJson($url, $answer)->assertOk()->assertJsonPath('data.summary.round.attempts', 1);
        $this->travel(1)->seconds();
        $next = $this->postJson($url, ['action' => 'next', 'version' => $this->version($student), 'attempt_id' => $prompt, 'feedback_ms' => 1000])->assertOk()->json('data.question.id');
        $this->travel(2)->seconds();
        $this->postJson($url, ['action' => 'answer', 'version' => $this->version($student), 'attempt_id' => $next, 'answer' => 4, 'response_ms' => 2000])->assertOk()->assertJsonPath('data.complete', true)->assertJsonPath('data.summary.round.practice_ms', 5000);
        $this->assertDatabaseHas('assignments', ['id' => $round, 'status' => 'submitted', 'is_extra_practice' => true]);
        $this->getJson(self::API.'/routine/finish')->assertOk()->assertJsonCount(0, 'data.remaining')->assertJsonPath('data.awaiting_review_count', 0);
        $this->getJson(self::API.'/games')->assertOk()->assertJsonPath('data.0.latest_round_id', $round)->assertJsonPath('data.0.rounds_count', 1);
        $game->update(['active' => false]);
        $this->getJson(self::API.'/games')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::API.'/games/'.$game->id.'/play', ['version' => $this->version($student)])->assertConflict();
        $this->getJson($url)->assertOk()->assertJsonPath('data.practice.complete', true);
    }

    public function test_spelling_supports_cover_listen_choose_peek_pause_and_stale_question_rejection(): void
    {
        $this->freezeTime();
        $student = User::factory()->create(['role' => 'student']);
        $work = $this->work($student, ['activity_type' => 'spelling', 'spelling_words' => ['because'], 'question_target' => 3, 'minutes' => 5]);
        $this->withToken($this->login($student));
        $this->postJson(self::API.'/routine', ['action' => 'day-start', 'version' => 0])->assertOk();
        $url = self::API.'/practice/'.$work->id;
        $id = $this->postJson($url, ['action' => 'start', 'version' => $this->version($student)])->assertOk()->assertJsonPath('data.question.mode', 'study')->json('data.question.id');
        $this->postJson($url, ['action' => 'cover', 'attempt_id' => $id, 'version' => $this->version($student)])->assertOk()->assertJsonPath('data.question.word', null);
        $this->getJson($url)->assertOk()->assertJsonPath('data.practice.question.word', null);
        $this->postJson($url, ['action' => 'help', 'attempt_id' => $id, 'version' => $this->version($student)])->assertOk()->assertJsonPath('data.question.word', 'because');
        $this->postJson($url, ['action' => 'cover', 'attempt_id' => $id, 'version' => $this->version($student)])->assertOk();
        $this->travel(2)->seconds();
        $this->postJson($url, ['action' => 'answer', 'attempt_id' => $id, 'version' => $this->version($student), 'answer' => 'because', 'response_ms' => 2000])->assertOk()->assertJsonPath('data.result.assisted', true);
        $id = $this->postJson($url, ['action' => 'next', 'version' => $this->version($student)])->assertOk()->assertJsonPath('data.question.mode', 'listen')->json('data.question.id');
        $this->postJson($url, ['action' => 'listen', 'attempt_id' => $id, 'version' => $this->version($student)])->assertOk()->assertJsonPath('data.speech', 'because');
        $this->travel(2)->seconds();
        $this->postJson($url, ['action' => 'answer', 'attempt_id' => $id, 'version' => $this->version($student), 'answer' => 'because', 'response_ms' => 2000])->assertOk();
        $id = $this->postJson($url, ['action' => 'next', 'version' => $this->version($student)])->assertOk()->assertJsonPath('data.question.mode', 'choose')->json('data.question.id');
        $this->postJson($url, ['action' => 'listen', 'attempt_id' => $id, 'version' => $this->version($student)])->assertOk();
        $this->postJson($url, ['action' => 'pause', 'version' => $this->version($student)])->assertOk()->assertJsonPath('data.running', false);
        $this->postJson($url, ['action' => 'answer', 'attempt_id' => $id, 'version' => $this->version($student), 'answer' => 'because', 'response_ms' => 100])->assertConflict();
        $id = $this->postJson($url, ['action' => 'start', 'version' => $this->version($student)])->assertOk()->json('data.question.id');
        $this->postJson($url, ['action' => 'listen', 'attempt_id' => $id, 'version' => $this->version($student)])->assertOk();
        $this->travel(2)->seconds();
        $this->postJson($url, ['action' => 'answer', 'attempt_id' => $id, 'version' => $this->version($student), 'answer' => 'because', 'response_ms' => 2000])->assertOk()->assertJsonPath('data.complete', true);
        $this->getJson($url)->assertOk()->assertJsonPath('data.report.round.attempts', 3)->assertJsonCount(3, 'data.report.modes');
    }
}
