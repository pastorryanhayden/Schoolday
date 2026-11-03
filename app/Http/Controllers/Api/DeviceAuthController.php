<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

abstract class DeviceAuthController extends Controller
{
    abstract protected function role(): string;

    public function login(Request $request): JsonResponse
    {
        $role = $this->role();
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string|max:1024', 'device_name' => 'required|string|max:100']);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || $user->role !== $role) {
            throw ValidationException::withMessages(['email' => 'The '.$role.' email or password is incorrect.']);
        }
        $plain = Str::random(64);
        $token = DeviceToken::create(['role' => $role, 'user_id' => $user->id, 'name' => $data['device_name'], 'token_hash' => hash('sha256', $plain), 'password_fingerprint' => DeviceToken::fingerprint($user), 'expires_at' => now()->addDays(30)]);

        return response()->json(['data' => ['token' => $plain, 'token_type' => 'Bearer', 'expires_at' => $token->expires_at->toIso8601String(), 'device_id' => $token->id, 'user' => $user->only(['id', 'name', 'email', 'role'])]], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->only(['id', 'name', 'email', 'role'])]);
    }

    public function devices(Request $request): JsonResponse
    {
        $tokens = DeviceToken::where('user_id', $request->user()->id)->where('role', $this->role())->where('expires_at', '>', now())->where('password_fingerprint', DeviceToken::fingerprint($request->user()))->orderBy('id')->get();

        return response()->json(['data' => $tokens->map(fn (DeviceToken $token): array => $token->only(['id', 'name', 'created_at', 'expires_at', 'last_used_at']) + ['current' => $token->id === $request->attributes->get('device_token')->id])]);
    }

    public function revoke(Request $request, DeviceToken $deviceToken): JsonResponse
    {
        abort_unless($deviceToken->user_id === $request->user()->id, 404);
        $deviceToken->delete();

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('device_token')->delete();

        return response()->json(['data' => ['logged_out' => true]]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        DeviceToken::where('user_id', $request->user()->id)->delete();

        return response()->json(['data' => ['logged_out' => true]]);
    }
}
