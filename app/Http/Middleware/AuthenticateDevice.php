<?php

namespace App\Http\Middleware;

use App\Models\DeviceToken;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDevice implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $bearer = $request->bearerToken();
        abort_unless(is_string($bearer) && strlen($bearer) === 64, 401, 'Sign in to your '.$role.' account.');
        $token = DeviceToken::with('user')->where('token_hash', hash('sha256', $bearer))->first();
        abort_unless($token && $token->expires_at->isFuture() && $token->user && hash_equals($token->password_fingerprint, DeviceToken::fingerprint($token->user)), 401, 'Your device session has expired or been revoked. Sign in again.');
        abort_unless($token->user->role === $role && $token->role === $role, 403, 'This API requires a '.$role.' device session.');
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('device_token', $token);
        $token->update(['last_used_at' => now()]);

        return $next($request);
    }
}
