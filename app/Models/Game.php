<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'active' => 'boolean'];
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }
}
