<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Message;
use App\Models\Recurrence;
use App\Models\User;
use App\Services\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchooldayTest extends TestCase
{
    use RefreshDatabase;

    private User $dad;

    private User $noah;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dad = User::factory()->create(['role' => 'parent']);
        $this->noah = User::factory()->create(['role' => 'student']);
        Storage::fake('local');
    }

    private function work(array $data = []): Assignment
    {
        return Assignment::create($data + ['student_id' => $this->noah->id, 'date' => today()->toDateString(), 'subject' => 'Writing', 'title' => 'Paragraph practice', 'instructions' => 'Write a paragraph.', 'requires_photo' => true]);
    }

    public function test_guests_must_log_in_and_registration_is_closed(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->post('/parent/assignments')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
    }

    public function test_login_logout_and_invalid_credentials(): void
    {
        $this->post('/login', ['email' => $this->dad->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $this->dad->email, 'password' => 'password', 'remember' => 1])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->dad);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_both_dashboards_and_assignment_pages_render(): void
    {
        $a = $this->work();
        $this->actingAs($this->dad)->get('/')->assertOk()->assertSee('Today’s plan');
        $this->get('/assignments/'.$a->id)->assertOk();
        $this->actingAs($this->noah)->get('/')->assertOk()->assertDontSee('Assignment details')->assertDontSee('Add assignment');
        $this->get('/assignments/'.$a->id)->assertOk()->assertSee('Submit to Dad');
    }

    public function test_student_cannot_access_any_parent_mutation_or_review(): void
    {
        $a = $this->work(['status' => 'submitted']);
        $r = Recurrence::create(['student_id' => $this->noah->id, 'starts_on' => today(), 'weekdays' => [1], 'assignment_data' => []]);
        $this->actingAs($this->noah);
        foreach (['/parent/assignments', '/parent/assignments/'.$a->id, '/parent/reorder', '/parent/copy', '/parent/recurrences', '/parent/recurrences/'.$r->id.'/stop', '/assignments/'.$a->id.'/review'] as $url) {
            $this->post($url)->assertForbidden();
        }
    }

    public function test_foreign_student_cannot_read_or_change_work_or_photos(): void
    {
        $a = $this->work();
        Storage::disk('local')->put('secret.jpg', 'private');
        $p = $a->photos()->create(['path' => 'secret.jpg', 'name' => 'secret.jpg']);
        $other = User::factory()->create(['role' => 'student']);
        $this->get('/photos/'.$p->id)->assertRedirect('/login');
        $this->actingAs($other)->get('/photos/'.$p->id)->assertForbidden();
        $this->get('/assignments/'.$a->id)->assertForbidden();
        $this->post('/assignments/'.$a->id.'/start')->assertForbidden();
        $this->post('/assignments/'.$a->id.'/submit')->assertForbidden();
        $this->delete('/photos/'.$p->id)->assertForbidden();
        $this->actingAs($this->dad)->get('/photos/'.$p->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_required_photo_and_full_correction_lifecycle(): void
    {
        $a = $this->work();
        $url = '/assignments/'.$a->id;
        $this->actingAs($this->noah)->post('/routine', ['action' => 'day-start', 'version' => 0])->assertRedirect();
        $this->post($url.'/start', ['version' => $this->noah->fresh()->routine_version])->assertRedirect();
        $this->assertEquals('in_progress', $a->fresh()->status);
        $this->post($url.'/submit', ['note' => 'Done'])->assertSessionHasErrors('photo');
        $this->postJson($url.'/photos', ['photo' => UploadedFile::fake()->image('page.jpg')])->assertOk();
        $photo = $a->photos()->first();
        Storage::disk('local')->assertExists($photo->path);
        $this->post($url.'/submit', ['note' => 'My first draft'])->assertRedirect();
        $this->assertEquals('submitted', $a->fresh()->status);
        $this->assertEquals(1, $a->fresh()->revision);
        $this->postJson($url.'/draft', ['note' => 'Sneaky edit'])->assertStatus(409);
        $this->deleteJson('/photos/'.$photo->id)->assertStatus(409);
        $this->actingAs($this->dad)->post($url.'/review', ['decision' => 'needs_changes'])->assertSessionHasErrors('feedback');
        $this->post($url.'/review', ['decision' => 'needs_changes', 'feedback' => 'Fix the ending.'])->assertRedirect();
        $this->assertEquals('needs_changes', $a->fresh()->status);
        $this->actingAs($this->noah)->deleteJson('/photos/'.$photo->id)->assertStatus(409);
        $this->postJson($url.'/draft', ['note' => 'Corrected'])->assertOk();
        $this->assertEquals('Corrected', $a->fresh()->note);
        $this->postJson($url.'/photos', ['photo' => UploadedFile::fake()->image('revision.jpg')])->assertOk();
        $this->post($url.'/submit', ['note' => 'Ending fixed.'])->assertRedirect();
        $this->assertEquals(2, $a->fresh()->revision);
        $this->actingAs($this->dad)->post($url.'/review', ['decision' => 'approved', 'feedback' => 'Good improvement.'])->assertRedirect();
        $this->assertEquals('approved', $a->fresh()->status);
        $this->assertNotNull($a->fresh()->approved_at);
        $this->assertEquals(2, $a->photos()->count());
        $this->assertDatabaseHas('activities', ['kind' => 'resubmission', 'assignment_id' => $a->id]);
        $this->assertDatabaseCount('messages', 2);
        $this->actingAs($this->noah)->post($url.'/submit')->assertStatus(409);
    }

    public function test_photo_validation_draft_removal_and_optional_evidence(): void
    {
        $a = $this->work();
        $this->actingAs($this->noah);
        $this->postJson('/assignments/'.$a->id.'/photos', ['photo' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')])->assertUnprocessable();
        $this->postJson('/assignments/'.$a->id.'/photos', ['photo' => UploadedFile::fake()->image('huge.jpg')->size(10241)])->assertUnprocessable();
        $this->postJson('/assignments/'.$a->id.'/photos', ['photo' => UploadedFile::fake()->image('ok.jpg')])->assertOk();
        $p = $a->photos()->first();
        $this->deleteJson('/photos/'.$p->id)->assertOk();
        Storage::disk('local')->assertMissing($p->path);
        $optional = $this->work(['requires_photo' => false]);
        $this->post('/assignments/'.$optional->id.'/submit')->assertRedirect();
        $this->assertEquals('submitted', $optional->fresh()->status);
    }

    public function test_recurrence_is_idempotent_and_obeys_weekdays_dates_and_stop(): void
    {
        $r = Recurrence::create(['student_id' => $this->noah->id, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-06', 'weekdays' => [1, 3, 5], 'assignment_data' => ['subject' => 'Spelling', 'title' => 'Practice', 'instructions' => 'Write words.', 'position' => 0, 'requires_photo' => true]]);
        foreach (['2026-11-01', '2026-11-02', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-06', '2026-11-09'] as $date) {
            School::generate($date);
        }
        $this->assertDatabaseCount('assignments', 3);
        $r->update(['active' => false]);
        School::generate('2026-11-05');
        $this->assertDatabaseCount('assignments', 3);
    }

    public function test_messages_are_private_and_read_only_through_the_visible_message(): void
    {
        $other = User::factory()->create(['role' => 'student']);
        $a = $this->work();
        $this->actingAs($this->noah)->post('/messages', ['body' => 'Help with this', 'help' => 1, 'assignment_id' => $a->id])->assertRedirect();
        $m = Message::first();
        $this->actingAs($this->dad)->get('/')->assertSee('Help with this');
        $this->postJson('/heartbeat')->assertJsonPath('unread', 1);
        $later = Message::create(['sender_id' => $this->noah->id, 'student_id' => $this->noah->id, 'body' => 'Later']);
        $this->postJson('/messages/read', ['through' => $m->id])->assertOk();
        $this->assertNotNull($m->fresh()->read_at);
        $this->assertNull($later->fresh()->read_at);
        $this->actingAs($other)->get('/')->assertDontSee('Help with this');
        $this->post('/messages', ['body' => 'Other', 'assignment_id' => $a->id])->assertForbidden();
        $this->actingAs($this->dad)->post('/messages', ['body' => 'I can help', 'assignment_id' => $a->id])->assertRedirect();
        $this->actingAs($this->noah)->postJson('/heartbeat')->assertJsonPath('unread', 1);
    }

    public function test_parent_creation_edit_order_copy_and_student_break_activity(): void
    {
        $data = ['date' => today()->toDateString(), 'subject' => 'Math', 'title' => 'Fractions', 'instructions' => 'Do lesson 42.', 'reference' => 'Page 12', 'minutes' => 30, 'position' => 0, 'requires_photo' => 0];
        $this->actingAs($this->dad)->post('/parent/assignments', $data)->assertRedirect();
        $a = Assignment::first();
        $this->post('/parent/assignments/'.$a->id, array_replace($data, ['title' => 'New title']))->assertRedirect();
        $this->assertEquals('New title', $a->fresh()->title);
        $b = $this->work(['position' => 1]);
        $this->postJson('/parent/reorder', ['ids' => [$b->id, $a->id]])->assertOk();
        $this->assertEquals(0, $b->fresh()->position);
        $this->post('/parent/copy', ['from' => today()->toDateString(), 'to' => today()->addDay()->toDateString()])->assertRedirect();
        $this->assertDatabaseCount('assignments', 4);
        $this->actingAs($this->noah)->post('/routine', ['action' => 'day-start', 'version' => 0])->assertRedirect();
        $this->post('/assignments/'.$a->id.'/start', ['version' => $this->noah->fresh()->routine_version])->assertRedirect();
        $this->actingAs($this->dad)->post('/parent/assignments/'.$a->id, $data)->assertStatus(409);
        $this->actingAs($this->noah)->post('/break', ['on_break' => 1, 'version' => $this->noah->fresh()->routine_version])->assertRedirect();
        $this->assertTrue($this->noah->fresh()->on_break);
        $this->post('/break', ['on_break' => 0, 'version' => $this->noah->fresh()->routine_version]);
        $this->assertDatabaseHas('activities', ['kind' => 'break']);
        $this->assertDatabaseHas('activities', ['kind' => 'resume']);
    }

    public function test_heartbeat_is_contact_only_and_parent_cannot_impersonate_student(): void
    {
        $a = $this->work();
        $this->actingAs($this->dad)->post('/assignments/'.$a->id.'/start')->assertForbidden();
        $this->postJson('/heartbeat')->assertOk();
        $this->assertNull($this->noah->fresh()->last_seen_at);
        $this->actingAs($this->noah)->postJson('/heartbeat')->assertOk();
        $this->assertNotNull($this->noah->fresh()->last_seen_at);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_current_assignment_tracks_corrections_without_resetting_previous_progress(): void
    {
        $first = $this->work(['requires_photo' => false]);
        $correction = $this->work(['status' => 'needs_changes', 'requires_photo' => false, 'revision' => 1, 'feedback' => 'Please correct.']);
        $this->actingAs($this->noah)->post('/routine', ['action' => 'day-start', 'version' => 0])->assertRedirect();
        $this->post('/assignments/'.$first->id.'/start', ['version' => $this->noah->fresh()->routine_version])->assertRedirect();
        $started = $first->fresh()->started_at;
        $this->post('/assignments/'.$correction->id.'/start', ['version' => $this->noah->fresh()->routine_version])->assertRedirect();
        $this->assertEquals($correction->id, $this->noah->fresh()->current_assignment_id);
        $this->assertEquals('needs_changes', $correction->fresh()->status);
        $this->assertEquals('in_progress', $first->fresh()->status);
        $this->assertEquals($started, $first->fresh()->started_at);
        $this->post('/assignments/'.$correction->id.'/submit')->assertRedirect();
        $this->assertNull($this->noah->fresh()->current_assignment_id);
    }

    public function test_recurring_assignment_form_and_stop_preserve_generated_work(): void
    {
        $data = ['date' => '2026-11-02', 'subject' => 'Handwriting', 'title' => 'Daily practice', 'instructions' => 'One page.', 'position' => 0, 'requires_photo' => 1, 'weekdays' => [1, 2, 3, 4, 5], 'ends_on' => '2026-11-06'];
        $this->actingAs($this->dad)->post('/parent/recurrences', $data)->assertRedirect();
        $rule = Recurrence::first();
        $this->assertNotNull($rule);
        $this->assertDatabaseCount('assignments', 1);
        School::generate('2026-11-02');
        $this->assertDatabaseCount('assignments', 1);
        $this->post('/parent/recurrences/'.$rule->id.'/stop')->assertRedirect();
        School::generate('2026-11-03');
        $this->assertDatabaseCount('assignments', 1);
        $this->assertFalse($rule->fresh()->active);
    }

    public function test_review_cannot_be_repeated_and_private_html_is_not_cached(): void
    {
        $a = $this->work(['status' => 'submitted']);
        $this->actingAs($this->dad)->post('/assignments/'.$a->id.'/review', ['decision' => 'approved'])->assertRedirect();
        $this->post('/assignments/'.$a->id.'/review', ['decision' => 'needs_changes', 'feedback' => 'Too late'])->assertStatus(409);
        $this->assertEquals('approved', $a->fresh()->status);
        $this->get('/')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Frame-Options', 'DENY');
    }
}
