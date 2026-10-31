<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolDay extends Model
{
    protected $guarded = [];

    public function setDateAttribute(mixed $value): void
    {
        $this->attributes['date'] = Carbon::parse($value)->toDateString();
    }

    protected function casts(): array
    {
        return ['date' => 'date', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WorkSession::class)->orderBy('started_at')->orderBy('id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(TimelineCorrection::class)->orderBy('id');
    }
}
