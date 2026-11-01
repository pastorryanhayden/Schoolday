<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GameFactory extends Factory
{
    public function definition(): array
    {
        return ['student_id' => User::factory()->state(['role' => 'student']), 'title' => 'Times tables', 'active' => true, 'configuration' => ['activity_type' => 'multiplication', 'subject' => 'Math', 'title' => 'Times tables', 'instructions' => 'Practice accurate recall.', 'minutes' => 5, 'fact_min' => 1, 'fact_max' => 12, 'question_target' => 2, 'requires_photo' => false]];
    }
}
