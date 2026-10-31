<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimelineCorrection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
