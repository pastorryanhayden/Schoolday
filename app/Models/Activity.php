<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $guarded = [];

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    protected function casts(): array
    {
        return [];
    }
}
