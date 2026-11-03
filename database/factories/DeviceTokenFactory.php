<?php

namespace Database\Factories;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DeviceToken> */
class DeviceTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => 'student']),
            'name' => 'Test iPhone',
            'role' => fn (array $attributes): string => User::findOrFail($attributes['user_id'])->role,
            'token_hash' => hash('sha256', Str::random(64)),
            'password_fingerprint' => fn (array $attributes): string => DeviceToken::fingerprint(User::findOrFail($attributes['user_id'])),
            'expires_at' => now()->addDays(30),
        ];
    }
}
