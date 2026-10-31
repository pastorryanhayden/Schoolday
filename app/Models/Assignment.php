<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    protected $guarded = [];

    public function setDateAttribute($value): void
    {
        $this->attributes['date'] = Carbon::parse($value)->toDateString();
    }

    protected function casts(): array
    {
        return ['date' => 'date', 'requires_photo' => 'boolean', 'started_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function photos()
    {
        return $this->hasMany(Photo::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
