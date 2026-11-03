<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentMessageResource;
use App\Models\Message;
use App\Models\User;
use App\Services\StudentActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentMessageController extends Controller
{
    protected function student(Request $request): User
    {
        return $request->user();
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['after' => 'nullable|integer|min:0|prohibits:before', 'before' => 'nullable|integer|min:1|prohibits:after', 'limit' => 'nullable|integer|min:1|max:100']);
        $query = Message::where('student_id', $this->student($request)->id)->with(['sender', 'assignment']);
        $forward = isset($data['after']);
        if ($forward) {
            $query->where('id', '>', $data['after'])->orderBy('id');
        } else {
            $query->when(isset($data['before']), fn ($query) => $query->where('id', '<', $data['before']))->orderByDesc('id');
        }
        $limit = $data['limit'] ?? 100;
        $messages = $query->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;
        $messages = $messages->take($limit);
        $messages = $forward ? $messages->values() : $messages->reverse()->values();

        return response()->json(['data' => StudentMessageResource::collection($messages), 'meta' => ['has_more' => $hasMore, 'next_after' => $messages->last()?->id ?? ($data['after'] ?? 0), 'next_before' => $messages->first()?->id, 'unread' => Message::where('student_id', $this->student($request)->id)->where('sender_id', '!=', $request->user()->id)->whereNull('read_at')->count()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $message = StudentActions::message($request->user(), $request->only(['body', 'assignment_id', 'help']));

        return (new StudentMessageResource($message->load(['sender', 'assignment'])))->response()->setStatusCode(201);
    }

    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['through' => 'required|integer|min:1']);
        $query = Message::where('student_id', $this->student($request)->id);
        (clone $query)->findOrFail($data['through']);
        $count = $query->where('sender_id', '!=', $request->user()->id)->where('id', '<=', $data['through'])->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['read_through' => (int) $data['through'], 'marked_read' => $count]]);
    }
}
