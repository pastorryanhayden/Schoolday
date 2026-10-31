<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['help' => 'boolean', 'read_at' => 'datetime'];
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }
}
