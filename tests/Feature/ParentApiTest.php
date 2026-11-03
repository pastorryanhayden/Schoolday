<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Book;
use App\Models\DeviceToken;
use App\Models\Game;
use App\Models\Message;
use App\Models\Recurrence;
use App\Models\SchoolDay;
use App\Models\User;
use App\Services\DailyRoutine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ParentApiTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/parent';

    private function family(): array
    {
        $this->freezeTime();

        return [User::factory()->create(['role' => 'parent']), User::factory()->create(['role' => 'student'])];
    }

    private function login(User $parent): string
    {
        return $this->postJson(self::API.'/login', ['email' => $parent->email, 'password' => 'password', 'device_name' => 'Parent iPhone'])->assertCreated()->json('data.token');
    }

    private function work(User $student, array $data = []): Assignment
    {
        return Assignment::create($data + ['student_id' => $student->id, 'date' => today()->toDateString(), 'subject' => 'Math', 'title' => 'Fractions', 'instructions' => 'Complete the lesson.', 'requires_photo' => false]);
    }

    public function test_parent_authentication_supports_device_revocation_logout_and_password_invalidation(): void
    {
        [$parent] = $this->family();
        $first = $this->login($parent);
        $second = $this->login($parent);
        $this->withToken($first)->getJson(self::API.'/me')->assertOk()->assertJsonPath('data.role', 'parent')->assertJsonMissingPath('data.password');
        $devices = $this->getJson(self::API.'/devices')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.token_hash')->json('data');
        $this->deleteJson(self::API.'/devices/'.$devices[1]['id'])->assertOk();
        $this->withToken($second)->getJson(self::API.'/me')->assertUnauthorized();
        $this->withToken($first)->postJson(self::API.'/logout')->assertOk();
        $this->getJson(self::API.'/me')->assertUnauthorized();
        $third = $this->login($parent);
        $fourth = $this->login($parent);
        $this->withToken($third)->postJson(self::API.'/logout-all')->assertOk();
        $this->withToken($fourth)->getJson(self::API.'/me')->assertUnauthorized();
        $fifth = $this->login($parent);
        $parent->update(['password' => 'replacement-password']);
        $this->withToken($fifth)->getJson(self::API.'/me')->assertUnauthorized();
    }

    public function test_all_parent_routes_require_parent_device_authentication_and_student_login_is_refused(): void
    {
        [$parent, $student] = $this->family();
        $work = $this->work($student);
        $photo = $work->photos()->create(['path' => 'private.jpg', 'name' => 'Worksheet']);
        $book = Book::factory()->create(['student_id' => $student->id]);
        $game = Game::factory()->create(['student_id' => $student->id]);
        $rule = Recurrence::create(['student_id' => $student->id, 'starts_on' => today(), 'weekdays' => [1], 'assignment_data' => ['title' => 'Reading']]);
        DailyRoutine::transition($student, 'day-start', 0);
        $day = SchoolDay::firstOrFail();
        $session = $day->sessions()->firstOrFail();
        $device = DeviceToken::factory()->create(['user_id' => $parent->id]);
        $requests = [
            ['GET', '/me'], ['GET', '/devices'], ['DELETE', '/devices/'.$device->id], ['POST', '/logout'], ['POST', '/logout-all'],
            ['GET', '/day'], ['GET', '/reviews'], ['GET', '/activity'], ['POST', '/heartbeat'], ['GET', '/timeline'], ['POST', '/timeline/'.$day->id.'/periods'], ['PATCH', '/timeline/'.$day->id.'/periods/'.$session->id], ['PATCH', '/timeline/'.$day->id],
            ['GET', '/assignments'], ['POST', '/assignments'], ['GET', '/assignments/'.$work->id], ['PATCH', '/assignments/'.$work->id], ['POST', '/assignments/'.$work->id.'/review'], ['POST', '/plan'], ['POST', '/copy'], ['POST', '/reorder'], ['GET', '/photos/'.$photo->id],
            ['GET', '/messages'], ['POST', '/messages'], ['POST', '/messages/read'], ['GET', '/books'], ['POST', '/books'], ['PATCH', '/books/'.$book->id], ['GET', '/games'], ['POST', '/games'], ['PATCH', '/games/'.$game->id], ['GET', '/recurrences'], ['POST', '/recurrences'], ['POST', '/recurrences/'.$rule->id.'/stop'], ['GET', '/practice/'.$work->id], ['GET', '/facts'],
        ];
        $this->actingAs($parent);
        foreach ($requests as [$method, $path]) {
            $this->json($method, self::API.$path)->assertUnauthorized();
        }
        $token = $this->postJson('/api/v1/student/login', ['email' => $student->email, 'password' => 'password', 'device_name' => 'iPad'])->assertCreated()->json('data.token');
        $this->withToken($token);
        foreach ($requests as [$method, $path]) {
            $this->json($method, self::API.$path)->assertForbidden();
        }
        $this->postJson(self::API.'/login', ['email' => $student->email, 'password' => 'password', 'device_name' => 'iPhone'])->assertUnprocessable();
        $this->assertDatabaseCount('assignments', 1);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_parent_tokens_cannot_operate_student_controls_or_gain_privileges_after_role_changes(): void
    {
        [$parent, $student] = $this->family();
        $work = $this->work($student);
        $token = $this->login($parent);
        $this->withToken($token)->postJson('/api/v1/student/routine', ['action' => 'day-start', 'version' => 0])->assertForbidden();
        $this->postJson('/api/v1/student/assignments/'.$work->id.'/submit')->assertForbidden();
        $this->postJson('/api/v1/student/practice/'.$work->id, ['action' => 'answer'])->assertForbidden();
        $this->postJson(self::API.'/routine')->assertNotFound();
        $plain = str_repeat('s', 64);
        DeviceToken::factory()->create(['user_id' => $student->id, 'role' => 'student', 'token_hash' => hash('sha256', $plain)]);
        $student->update(['role' => 'parent']);
        $this->withToken($plain)->getJson(self::API.'/me')->assertForbidden();
        $this->assertDatabaseCount('school_days', 0);
    }

    public function test_parent_reads_use_the_student_scope_and_never_acknowledge_messages_or_update_contact(): void
    {
        [$parent, $student] = $this->family();
        $required = $this->work($student, ['status' => 'submitted', 'revision' => 1]);
        $this->work($student, ['is_extra_practice' => true, 'status' => 'submitted', 'revision' => 1]);
        $message = Message::create(['student_id' => $student->id, 'sender_id' => $student->id, 'body' => 'Please help']);
        $this->withToken($this->login($parent));

        $this->getJson(self::API.'/day')->assertOk()->assertJsonPath('data.student.id', $student->id)->assertJsonPath('data.progress.total', 1)->assertJsonPath('data.unread', 1);
        $this->getJson(self::API.'/assignments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $required->id);
        $this->getJson(self::API.'/reviews')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $required->id);
        $this->postJson(self::API.'/heartbeat')->assertOk()->assertJsonPath('data.last_seen', null)->assertJsonPath('data.unread', 1)->assertJsonPath('data.submitted', 1);
        $this->getJson(self::API.'/timeline')->assertOk()->assertJsonPath('data.version', 0);
        $this->assertNull($student->fresh()->last_seen_at);
        $this->assertNull($message->fresh()->read_at);
        $this->assertDatabaseCount('school_days', 0);
    }

    public function test_planning_creates_updates_copies_reorders_and_rejects_started_work_and_protected_fields(): void
    {
        [$parent, $student] = $this->family();
        $this->withToken($this->login($parent));
        $date = today()->toDateString();
        $data = ['date' => $date, 'subject' => 'Math', 'title' => 'Fractions', 'instructions' => 'Do lesson 12.'];
        $first = $this->postJson(self::API.'/assignments', $data)->assertCreated()->json('data.id');
        $this->patchJson(self::API.'/assignments/'.$first, ['title' => 'New lesson'])->assertOk()->assertJsonPath('data.title', 'New lesson');
        $this->patchJson(self::API.'/assignments/'.$first, ['status' => 'approved'])->assertUnprocessable();
        $second = $this->postJson(self::API.'/assignments', $data)->assertCreated()->json('data.id');
        $this->postJson(self::API.'/reorder', ['date' => $date, 'ids' => [$second, $first]])->assertOk();
        $this->assertDatabaseHas('assignments', ['id' => $second, 'position' => 0]);
        $this->assertDatabaseHas('assignments', ['id' => $first, 'position' => 1]);
        $this->postJson(self::API.'/reorder', ['date' => $date, 'ids' => [$first]])->assertUnprocessable();
        $this->postJson(self::API.'/copy', ['from' => $date, 'to' => today()->addDay()->toDateString()])->assertCreated()->assertJsonCount(2, 'data');
        Assignment::findOrFail($first)->update(['status' => 'in_progress', 'started_at' => now()]);
        $this->patchJson(self::API.'/assignments/'.$first, ['title' => 'Too late'])->assertConflict();
        $this->assertDatabaseCount('assignments', 4);
        $this->getJson(self::API.'/activity?after=0&limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', true);
    }

    public function test_bulk_plan_is_atomic_and_uses_the_top_level_date_and_book_defaults(): void
    {
        [$parent, $student] = $this->family();
        $book = Book::factory()->create(['student_id' => $student->id, 'title' => 'Arithmetic', 'subject' => 'Math']);
        $this->withToken($this->login($parent));
        $payload = ['date' => today()->toDateString(), 'assignments' => [['book_id' => $book->id, 'reference' => 'Lesson 4'], ['activity_type' => 'spelling', 'spelling_words' => ['friend', 'because']]]];
        $this->postJson(self::API.'/plan', $payload)->assertCreated()->assertJsonPath('data.0.position', 0)->assertJsonPath('data.0.title', 'Arithmetic')->assertJsonPath('data.1.position', 1);
        $payload['assignments'][1] = ['title' => 'Invalid'];
        $this->postJson(self::API.'/plan', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('assignments', 2);
        $this->assertDatabaseCount('activities', 2);
    }

    public function test_bulk_plan_appends_after_explicit_positions_without_including_extra_rounds(): void
    {
        [$parent, $student] = $this->family();
        $this->work($student, ['position' => 3]);
        $this->work($student, ['is_extra_practice' => true, 'position' => 999]);
        $this->withToken($this->login($parent));

        $this->postJson(self::API.'/plan', ['date' => today()->toDateString(), 'assignments' => [
            ['activity_type' => 'multiplication', 'position' => 5],
            ['activity_type' => 'multiplication'],
        ]])->assertCreated()->assertJsonPath('data.0.position', 5)->assertJsonPath('data.1.position', 6);
        $this->assertDatabaseCount('assignments', 4);
    }

    public function test_revision_checked_reviews_expose_private_evidence_and_require_correction_feedback(): void
    {
        Storage::fake('local');
        [$parent, $student] = $this->family();
        $work = $this->work($student, ['status' => 'submitted', 'revision' => 2, 'note' => 'My work']);
        Storage::disk('local')->put('worksheet.jpg', 'private image');
        $photo = $work->photos()->create(['path' => 'worksheet.jpg', 'name' => 'Worksheet', 'revision' => 2]);
        $this->withToken($this->login($parent));
        $url = self::API.'/assignments/'.$work->id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.note', 'My work')->assertJsonPath('data.revision', 2)->assertJsonPath('data.photos.0.url', self::API.'/photos/'.$photo->id)->assertJsonMissingPath('data.photos.0.path')->assertJsonStructure(['meta' => ['history']]);
        $this->get(self::API.'/photos/'.$photo->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($url.'/review', ['decision' => 'approved'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->postJson($url.'/review', ['decision' => 'approved', 'revision' => 1])->assertConflict();
        $this->postJson($url.'/review', ['decision' => 'needs_changes', 'revision' => 2])->assertUnprocessable()->assertJsonValidationErrors('feedback');
        $this->assertDatabaseCount('messages', 0);
        $this->postJson($url.'/review', ['decision' => 'needs_changes', 'feedback' => 'Fix line two.', 'revision' => 2])->assertOk()->assertJsonPath('data.status', 'needs_changes');
        $this->assertDatabaseHas('messages', ['sender_id' => $parent->id, 'student_id' => $student->id, 'assignment_id' => $work->id, 'body' => 'Please make corrections. Fix line two.']);
        $work->refresh()->update(['status' => 'submitted', 'revision' => 3]);
        $this->postJson($url.'/review', ['decision' => 'approved', 'revision' => 3])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertNotNull($work->fresh()->approved_at);
        $this->postJson($url.'/review', ['decision' => 'approved', 'revision' => 3])->assertConflict();
    }

    public function test_book_game_and_recurring_configuration_preserve_existing_assignments_and_archives(): void
    {
        [$parent, $student] = $this->family();
        $this->withToken($this->login($parent));
        $book = $this->postJson(self::API.'/books', ['title' => 'Math book', 'subject' => 'Math', 'instructions' => 'Do the lesson.'])->assertCreated()->json('data.id');
        $work = $this->postJson(self::API.'/assignments', ['date' => today()->toDateString(), 'book_id' => $book])->assertCreated()->json('data.id');
        $this->patchJson(self::API.'/books/'.$book, ['title' => 'New title', 'active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->assertDatabaseHas('assignments', ['id' => $work, 'title' => 'Math book']);
        $this->getJson(self::API.'/books')->assertOk()->assertJsonPath('data.0.active', false);
        $game = $this->postJson(self::API.'/games', ['activity_type' => 'spelling', 'title' => 'Week 1', 'spelling_words' => ['friend'], 'question_target' => 3])->assertCreated()->json('data.id');
        $this->patchJson(self::API.'/games/'.$game, ['spelling_words' => ['because'], 'active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->getJson(self::API.'/games')->assertOk()->assertJsonPath('data.0.configuration.spelling_words', ['because']);
        $this->postJson(self::API.'/games', ['activity_type' => 'multiplication', 'fact_min' => 12, 'fact_max' => 1])->assertUnprocessable();
        $rule = $this->postJson(self::API.'/recurrences', ['date' => today()->toDateString(), 'weekdays' => [today()->dayOfWeekIso], 'book_id' => $book])->assertCreated()->json('data.id');
        $this->getJson(self::API.'/recurrences')->assertOk()->assertJsonPath('data.0.id', $rule);
        $this->postJson(self::API.'/recurrences/'.$rule.'/stop')->assertOk()->assertJsonPath('data.active', false);
        $this->assertDatabaseCount('assignments', 2);
    }

    public function test_parent_messages_have_parent_identity_and_only_explicit_acknowledgment_changes_reads(): void
    {
        [$parent, $student] = $this->family();
        $work = $this->work($student);
        $old = Message::create(['student_id' => $student->id, 'sender_id' => $student->id, 'body' => 'Help']);
        $new = Message::create(['student_id' => $student->id, 'sender_id' => $student->id, 'body' => 'Newer']);
        $this->withToken($this->login($parent));
        $this->getJson(self::API.'/messages?limit=1')->assertOk()->assertJsonPath('data.0.id', $new->id)->assertJsonPath('data.0.is_mine', false);
        $this->getJson(self::API.'/messages?before='.$new->id)->assertOk()->assertJsonPath('data.0.id', $old->id);
        $this->getJson(self::API.'/messages?after='.$old->id)->assertOk()->assertJsonPath('data.0.id', $new->id);
        $this->assertNull($old->fresh()->read_at);
        $id = $this->postJson(self::API.'/messages', ['body' => 'I can help', 'assignment_id' => $work->id, 'sender_id' => $student->id])->assertCreated()->assertJsonPath('data.sender.id', $parent->id)->assertJsonPath('data.is_mine', true)->json('data.id');
        $this->assertDatabaseHas('messages', ['id' => $id, 'student_id' => $student->id, 'sender_id' => $parent->id]);
        $this->postJson(self::API.'/messages/read', ['through' => $old->id])->assertOk()->assertJsonPath('data.marked_read', 1);
        $this->assertNull($new->fresh()->read_at);
        $this->getJson(self::API.'/messages')->assertOk()->assertJsonPath('meta.unread', 1);
    }

    public function test_parent_cannot_access_records_outside_the_configured_student_or_another_parents_devices(): void
    {
        [$parent, $student] = $this->family();
        $other = User::factory()->create(['role' => 'student']);
        $work = $this->work($other);
        $photo = $work->photos()->create(['name' => 'Secret', 'path' => 'secret.jpg']);
        $book = Book::factory()->create(['student_id' => $other->id]);
        $game = Game::factory()->create(['student_id' => $other->id]);
        $rule = Recurrence::create(['student_id' => $other->id, 'starts_on' => today(), 'weekdays' => [1], 'assignment_data' => ['title' => 'Secret']]);
        $message = Message::create(['student_id' => $other->id, 'sender_id' => $other->id, 'body' => 'Private']);
        $device = DeviceToken::factory()->create(['user_id' => User::factory()->create(['role' => 'parent'])->id]);
        $this->withToken($this->login($parent));
        foreach ([['GET', '/assignments/'.$work->id], ['PATCH', '/assignments/'.$work->id], ['POST', '/assignments/'.$work->id.'/review'], ['GET', '/photos/'.$photo->id], ['PATCH', '/books/'.$book->id], ['POST', '/recurrences/'.$rule->id.'/stop'], ['GET', '/practice/'.$work->id], ['DELETE', '/devices/'.$device->id]] as [$method, $path]) {
            $this->json($method, self::API.$path)->assertNotFound();
        }
        $this->patchJson(self::API.'/games/'.$game->id, ['title' => 'Changed'])->assertNotFound();
        $this->postJson(self::API.'/messages', ['body' => 'Hello', 'assignment_id' => $work->id])->assertNotFound();
        $this->postJson(self::API.'/messages/read', ['through' => $message->id])->assertNotFound();
        $this->getJson(self::API.'/assignments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::API.'/messages')->assertOk()->assertJsonCount(0, 'data');
        $this->assertModelExists($device);
    }

    public function test_timeline_period_and_day_corrections_require_current_version_and_preserve_audit_history(): void
    {
        [$parent, $student] = $this->family();
        DailyRoutine::transition($student, 'day-start', 0);
        $day = SchoolDay::firstOrFail();
        $session = $day->sessions()->firstOrFail();
        $this->travel(10)->minutes();
        DailyRoutine::transition($student, 'day-finish', $student->fresh()->routine_version);
        $this->withToken($this->login($parent));
        $timeline = $this->getJson(self::API.'/timeline')->assertOk()->json('data');
        $periodUrl = self::API.'/timeline/'.$day->id.'/periods/'.$session->id;
        $data = ['version' => $timeline['version'], 'started_at' => $day->started_at->toIso8601String(), 'ended_at' => $day->started_at->copy()->addMinutes(5)->toIso8601String(), 'reason' => 'Correcting a missed break.'];
        $this->patchJson($periodUrl, array_replace($data, ['version' => 0]))->assertConflict();
        $this->patchJson($periodUrl, array_replace($data, ['reason' => '']))->assertUnprocessable();
        $version = $this->patchJson($periodUrl, $data)->assertOk()->assertJsonCount(1, 'data.day.corrections')->json('data.version');
        $new = ['version' => $version, 'kind' => 'break', 'started_at' => $day->started_at->copy()->addMinutes(5)->toIso8601String(), 'ended_at' => $day->started_at->copy()->addMinutes(8)->toIso8601String(), 'reason' => 'Add the actual break.'];
        $version = $this->postJson(self::API.'/timeline/'.$day->id.'/periods', $new)->assertOk()->assertJsonPath('data.totals_seconds.break', 180)->json('data.version');
        $this->postJson(self::API.'/timeline/'.$day->id.'/periods', array_replace($new, ['version' => $version]))->assertUnprocessable();
        $this->patchJson(self::API.'/timeline/'.$day->id, ['version' => $version, 'started_at' => $day->started_at->toIso8601String(), 'finished_at' => $day->started_at->copy()->addMinutes(9)->toIso8601String(), 'reason' => 'Correct finish time.'])->assertOk()->assertJsonCount(3, 'data.day.corrections');
        $this->assertDatabaseHas('timeline_corrections', ['editor_id' => $parent->id, 'reason' => 'Correct finish time.']);
        $this->assertDatabaseCount('work_sessions', 2);
    }

    public function test_foreign_days_and_sessions_cannot_be_corrected(): void
    {
        [$parent, $student] = $this->family();
        $other = User::factory()->create(['role' => 'student']);
        DailyRoutine::transition($student, 'day-start', 0);
        DailyRoutine::transition($other, 'day-start', 0);
        $day = SchoolDay::where('student_id', $student->id)->firstOrFail();
        $foreign = SchoolDay::where('student_id', $other->id)->firstOrFail();
        $foreignSession = $foreign->sessions()->firstOrFail();
        $this->travel(5)->minutes();
        $this->withToken($this->login($parent));
        $data = ['version' => $student->fresh()->routine_version, 'started_at' => $day->started_at->toIso8601String(), 'ended_at' => now()->toIso8601String(), 'reason' => 'Correction'];
        $this->patchJson(self::API.'/timeline/'.$day->id.'/periods/'.$foreignSession->id, $data)->assertNotFound();
        $this->patchJson(self::API.'/timeline/'.$foreign->id, ['version' => $student->fresh()->routine_version, 'started_at' => $foreign->started_at->toIso8601String(), 'reason' => 'Correction'])->assertNotFound();
        $this->assertDatabaseCount('timeline_corrections', 0);
    }

    public function test_parent_can_read_both_practice_reports_and_fact_history_without_recording_answers(): void
    {
        [$parent, $student] = $this->family();
        $math = $this->work($student, ['activity_type' => 'multiplication', 'minutes' => 5]);
        $spelling = $this->work($student, ['activity_type' => 'spelling', 'spelling_words' => ['friend'], 'minutes' => 5]);
        $this->withToken($this->login($parent));
        $this->getJson(self::API.'/practice/'.$math->id)->assertOk()->assertJsonPath('data.report.round.attempts', 0)->assertJsonPath('data.facts.totals.attempts', 0);
        $this->getJson(self::API.'/practice/'.$spelling->id)->assertOk()->assertJsonPath('data.facts', null)->assertJsonPath('data.report.words', []);
        $this->getJson(self::API.'/facts')->assertOk()->assertJsonPath('data.totals.attempts', 0);
        $this->assertDatabaseCount('practice_attempts', 0);
        $this->assertDatabaseCount('work_sessions', 0);
    }
}
