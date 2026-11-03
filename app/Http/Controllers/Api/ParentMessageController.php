<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\StudentMessageResource;
use App\Models\User;
use App\Services\ParentActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentMessageController extends StudentMessageController
{
    protected function student(Request $request): User
    {
        return ParentActions::student();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:4000', 'assignment_id' => 'nullable|integer|min:1']);
        $message = ParentActions::message($request->user(), $data['body'], $data['assignment_id'] ?? null);

        return (new StudentMessageResource($message->load(['sender', 'assignment'])))->response()->setStatusCode(201);
    }
}
