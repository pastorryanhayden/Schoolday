<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Book;
use App\Models\User;
use App\Services\ParentActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_parents_can_read_or_change_book_settings(): void
    {
        $parent = User::factory()->create(['role' => 'parent']);
        $student = User::factory()->create(['role' => 'student']);
        $book = Book::factory()->for($student, 'student')->create();

        $this->get('/parent/settings')->assertRedirect('/login');
        $this->actingAs($student)->get('/parent/settings')->assertForbidden();
        $this->post('/parent/books', ['title' => 'Changed'])->assertForbidden();
        $this->post('/parent/books/'.$book->id, ['title' => 'Changed'])->assertForbidden();
        $this->actingAs($parent)->get('/parent/settings')->assertOk()->assertSee('Math workbook');
        $this->assertSame('Math workbook', $book->fresh()->title);
    }

    public function test_book_defaults_are_snapshotted_and_archiving_preserves_assignment_links(): void
    {
        $parent = User::factory()->create(['role' => 'parent']);
        $student = User::factory()->create(['role' => 'student']);
        $book = Book::factory()->for($student, 'student')->create(['requires_photo' => true]);

        $assignment = ParentActions::create($parent, ['book_id' => $book->id, 'date' => '2026-11-02']);
        ParentActions::saveBook($parent, ['instructions' => 'New instructions.', 'active' => false], $book->id);

        $this->assertSame('Complete the lesson and record your score.', $assignment->fresh()->instructions);
        $this->assertSame($book->id, $assignment->fresh()->book_id);
        $this->assertTrue($assignment->fresh()->requires_photo);
        $this->assertSame(40, $assignment->fresh()->minutes);
        $this->assertFalse($book->fresh()->active);
        $this->actingAs($student)->get('/assignments/'.$assignment->id)->assertOk()->assertSee('Math workbook')->assertDontSee('New instructions.');
    }

    public function test_parent_can_create_and_edit_a_book_and_invalid_data_is_rejected(): void
    {
        $parent = User::factory()->create(['role' => 'parent']);
        $student = User::factory()->create(['role' => 'student']);
        $data = ['title' => 'Spelling workbook', 'subject' => 'Spelling', 'publisher' => 'The Good and the Beautiful', 'level' => 'Level 5', 'minutes' => 15, 'requires_photo' => 1, 'active' => 1];

        $this->actingAs($parent)->post('/parent/books', $data)->assertRedirect('/parent/settings');

        $book = Book::firstOrFail();
        $this->assertSame($student->id, $book->student_id);
        $this->assertSame('Level 5', $book->level);
        $this->post('/parent/books/'.$book->id, array_replace($data, ['level' => 'Level 6', 'active' => 0]))->assertRedirect('/parent/settings');
        $this->assertSame('Level 6', $book->fresh()->level);
        $this->assertFalse($book->fresh()->active);
        $this->post('/parent/books', array_replace($data, ['title' => '', 'minutes' => 0]))->assertSessionHasErrors();
        $this->assertDatabaseCount('books', 1);
    }

    public function test_cli_manages_books_idempotently_and_can_plan_from_a_book_id(): void
    {
        User::factory()->create(['role' => 'parent']);
        $student = User::factory()->create(['role' => 'student']);
        $file = tempnam(sys_get_temp_dir(), 'schoolday-book-');
        try {
            file_put_contents($file, json_encode(['title' => 'Writing course', 'subject' => 'Writing', 'publisher' => 'Essentials in Writing', 'instructions' => 'Write a paragraph.', 'requires_photo' => true]));
            $this->artisan('schoolday', ['action' => 'book-add', '--file' => $file, '--key' => 'writing-book', '--dry-run' => true])->assertExitCode(0);
            $this->assertDatabaseCount('books', 0);
            $this->artisan('schoolday', ['action' => 'book-add', '--file' => $file, '--key' => 'writing-book'])->assertExitCode(0);
            $this->artisan('schoolday', ['action' => 'book-add', '--file' => $file, '--key' => 'writing-book'])->assertExitCode(0);
            $book = Book::firstOrFail();
            $this->assertDatabaseCount('books', 1);
            file_put_contents($file, json_encode(['date' => '2026-11-02', 'assignments' => [['book_id' => $book->id, 'reference' => 'Lesson 18']]]));

            $this->artisan('schoolday', ['action' => 'plan', '--file' => $file, '--key' => 'writing-day'])->assertExitCode(0);

            $assignment = Assignment::firstOrFail();
            $this->assertSame('Writing course', $assignment->title);
            $this->assertSame('Write a paragraph.', $assignment->instructions);
            $this->assertSame('Lesson 18', $assignment->reference);
            $this->assertSame($book->id, $assignment->book_id);
            $this->assertTrue($assignment->requires_photo);
            $this->assertSame($student->id, $assignment->student_id);
            file_put_contents($file, json_encode(['active' => false]));
            $this->artisan('schoolday', ['action' => 'book-update', 'target' => $book->id, '--file' => $file, '--key' => 'archive-writing'])->assertExitCode(0);
            $this->assertFalse($book->fresh()->active);
            $this->actingAs($student)->get('/')->assertDontSee('Settings · Books');
        } finally {
            unlink($file);
        }
    }

    public function test_foreign_book_links_are_rejected_without_changing_the_assignment(): void
    {
        $parent = User::factory()->create(['role' => 'parent']);
        $student = User::factory()->create(['role' => 'student']);
        $book = Book::factory()->create();
        $assignment = Assignment::create(['student_id' => $student->id, 'date' => '2026-11-02', 'subject' => 'Math', 'title' => 'Fractions', 'instructions' => 'Do lesson.']);

        $this->actingAs($parent)->post('/parent/assignments/'.$assignment->id, ['book_id' => $book->id, 'date' => '2026-11-02', 'subject' => 'Math', 'title' => 'Fractions', 'instructions' => 'Do lesson.', 'position' => 0, 'requires_photo' => 0])->assertSessionHasErrors();

        $this->assertNull($assignment->fresh()->book_id);
    }
}
