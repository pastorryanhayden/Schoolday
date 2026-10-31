<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PracticeAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['assisted_at' => 'datetime', 'issued_at' => 'datetime', 'answered_at' => 'datetime', 'correct' => 'boolean', 'interrupted' => 'boolean', 'response_ms' => 'integer', 'credited_ms' => 'integer', 'feedback_ms' => 'integer', 'feedback_recorded' => 'boolean', 'answer' => 'integer', 'left' => 'integer', 'right' => 'integer'];
    }
}
