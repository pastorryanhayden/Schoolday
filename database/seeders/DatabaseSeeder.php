<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Message;
use App\Models\User;
use App\Services\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Demo data is opt-in and must never be used to provision production accounts.
        if (app()->isProduction()) {
            throw new \RuntimeException('The demo seeder cannot run in production.');
        }
        $dad = User::firstOrCreate(['email' => 'ryan@example.test'], ['name' => 'Ryan Hayden', 'role' => 'parent', 'password' => 'SchooldayDad!26']);
        $noah = User::firstOrCreate(['email' => 'noah@example.test'], ['name' => 'Noah', 'role' => 'student', 'password' => 'SchooldayNoah!26']);
        if (Assignment::where('student_id', $noah->id)->exists()) {
            return;
        }
        $plan = [
            ['Math', 'Lesson 42 · Adding fractions', 'Watch the Teaching Textbooks lesson. Work through the practice problems, then complete the lesson. Write down your score in a note. If a problem is tricky, show your work in a photo.', 'Teaching Textbooks · Lesson 42', 40, false, 'in_progress'],
            ['Writing', 'Build a strong paragraph', 'Watch the lesson in Essentials in Writing. Draft a paragraph with a clear topic sentence, three supporting details, and a closing sentence. Photograph the completed draft.', 'Essentials in Writing · Lesson 18', 30, true, 'submitted'],
            ['Literature', 'Read & reflect', 'Read the assigned selection. Answer the comprehension questions in your workbook using complete sentences. Upload a photo of your answers.', 'Essentials in Literature · Unit 2, Lesson 6', 30, true, 'not_started'],
            ['Handwriting', 'Slow down. Write clearly.', 'Complete one page of handwriting. Pay attention to spacing and letter formation. Take a photo when you finish.', 'The Good and the Beautiful · Page 28', 15, true, 'approved'],
            ['Spelling', 'Practice this week’s words', 'Review the spelling list. Write each word, cover it, and write it again from memory. Please correct the two circled words and upload a new photo.', 'The Good and the Beautiful · List 12', 15, true, 'needs_changes'],
        ];
        foreach ($plan as $i => [$subject,$title,$instructions,$reference,$minutes,$requires,$status]) {
            $a = Assignment::create(['student_id' => $noah->id, 'date' => today(), 'subject' => $subject, 'title' => $title, 'instructions' => $instructions, 'reference' => $reference, 'minutes' => $minutes, 'requires_photo' => $requires, 'position' => $i, 'status' => $status, 'started_at' => $status !== 'not_started' ? now()->subMinutes(15 + $i * 20) : null, 'submitted_at' => in_array($status, ['submitted', 'approved', 'needs_changes']) ? now()->subMinutes(10 + $i * 15) : null, 'approved_at' => $status === 'approved' ? now()->subMinutes(15) : null, 'revision' => in_array($status, ['submitted', 'approved', 'needs_changes']) ? 1 : 0, 'note' => $status === 'submitted' ? 'I used the checklist to check my paragraph. Ready for you to take a look.' : null, 'feedback' => $status === 'needs_changes' ? 'Good effort. Please check the spelling of “because” and “different,” then write each correctly three times.' : ($status === 'approved' ? 'Careful spacing and clear letters. Nice work!' : null)]);
            if (in_array($status, ['submitted', 'approved', 'needs_changes'])) {
                $im = imagecreatetruecolor(1000, 1300);
                $paper = imagecolorallocate($im, 253, 252, 247);
                imagefill($im, 0, 0, $paper);
                $ink = imagecolorallocate($im, 59, 73, 86);
                $line = imagecolorallocate($im, 209, 223, 229);
                imagestring($im, 5, 75, 55, 'NOAH  /  '.$subject.'  /  SAMPLE WORKSHEET', $ink);
                imagestring($im, 4, 75, 100, 'Demo evidence - replace with real student work.', $ink);
                for ($y = 175; $y < 1200; $y += 55) {
                    imageline($im, 60, $y, 940, $y, $line);
                }
                $lines = $subject === 'Writing' ? ['A little practice makes a difference.', 'Learning something new can feel difficult at first.', 'When I break it into small steps, I can keep going.', 'Asking a question helps me understand the next step.', 'With practice, I can see my progress.'] : ['1. Today I practiced carefully.', '2. I checked my work.', '3. I am ready for Dad to review it.'];
                foreach ($lines as $j => $text) {
                    imagestring($im, 5, 80, 150 + $j * 55, $text, $ink);
                }
                ob_start();
                imagejpeg($im, null, 85);
                $bytes = ob_get_clean();
                unset($im);
                $path = 'worksheets/demo-'.$a->id.'.jpg';
                Storage::disk('local')->put($path, $bytes);
                $a->photos()->create(['path' => $path, 'name' => strtolower($subject).'-worksheet.jpg', 'revision' => 1]);
                Activity::create(['user_id' => $noah->id, 'assignment_id' => $a->id, 'kind' => 'submission', 'description' => 'Submitted '.$a->title, 'created_at' => $a->submitted_at, 'updated_at' => $a->submitted_at]);
            }
            if ($status === 'in_progress') {
                $noah->update(['current_assignment_id' => $a->id]);
                School::record($noah, 'start', 'Started '.$a->title, $a);
            }
        }
        Message::create(['sender_id' => $dad->id, 'student_id' => $noah->id, 'body' => 'Good morning, Noah! Start with math, and send me a message whenever you need a hand. You’ve got this.', 'read_at' => now()->subMinutes(30), 'created_at' => now()->subMinutes(45)]);
        $writing = Assignment::where('subject', 'Writing')->first();
        Message::create(['sender_id' => $noah->id, 'student_id' => $noah->id, 'assignment_id' => $writing->id, 'body' => 'My paragraph is ready. Could you check whether my closing sentence works?', 'help' => true, 'created_at' => now()->subMinutes(8)]);
        School::record($noah, 'help', 'Asked Dad for help with the writing assignment', $writing);
    }
}
