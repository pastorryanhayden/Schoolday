<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Model
{
    use HasFactory;

    protected $fillable = ['role', 'user_id', 'name', 'token_hash', 'password_fingerprint', 'expires_at', 'last_used_at'];

    protected $hidden = ['token_hash', 'password_fingerprint'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function fingerprint(User $user): string
    {
        return hash_hmac('sha256', $user->getAuthPassword(), config('app.key'));
    }
}
