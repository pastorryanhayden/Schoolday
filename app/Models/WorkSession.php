<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkSession extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'practice_ms' => 'integer'];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(SchoolDay::class, 'school_day_id');
    }

    public function seconds(): int
    {
        if ($this->kind === 'work' && $this->practice_ms !== null) {
            return (int) floor($this->practice_ms / 1000);
        }

        return max(0, (int) $this->started_at->diffInSeconds($this->ended_at ?? now()));
    }
}
