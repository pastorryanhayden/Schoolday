<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assignment extends Model
{
    protected $guarded = [];

    public function setDateAttribute($value): void
    {
        $this->attributes['date'] = Carbon::parse($value)->toDateString();
    }

    protected function casts(): array
    {
        return ['is_extra_practice' => 'boolean', 'spelling_words' => 'array', 'question_target' => 'integer', 'fact_min' => 'integer', 'fact_max' => 'integer', 'minutes' => 'integer', 'date' => 'date', 'requires_photo' => 'boolean', 'started_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function isPractice(): bool
    {
        return in_array($this->activity_type, ['multiplication', 'spelling']);
    }

    public function photos()
    {
        return $this->hasMany(Photo::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
