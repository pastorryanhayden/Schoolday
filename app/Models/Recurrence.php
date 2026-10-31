<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recurrence extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'assignment_data' => 'array', 'starts_on' => 'date', 'ends_on' => 'date', 'active' => 'boolean'];
    }
}
