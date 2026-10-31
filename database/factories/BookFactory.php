<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookFactory extends Factory
{
    public function definition(): array
    {
        return ['student_id' => User::factory()->state(['role' => 'student']), 'subject' => 'Math', 'title' => 'Math workbook', 'publisher' => 'Teaching Textbooks', 'level' => null, 'reference' => 'Lesson 43', 'instructions' => 'Complete the lesson and record your score.', 'minutes' => 40, 'requires_photo' => false, 'active' => true];
    }
}
