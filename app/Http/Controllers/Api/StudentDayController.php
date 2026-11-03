<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentAssignmentResource;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Message;
use App\Models\User;
use App\Services\DailyRoutine;
use App\Services\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentDayController extends Controller
{
    protected function student(Request $request): User
    {
        return $request->user();
    }

    private function date(Request $request): string
    {
        return $request->validate(['date' => 'nullable|date_format:Y-m-d'])['date'] ?? today()->toDateString();
    }

    public function show(Request $request): JsonResponse
    {
        $date = $this->date($request);
        School::generate($date);
        $student = $this->student($request);
        $query = Assignment::where('student_id', $student->id)->where('is_extra_practice', false)->with(['photos', 'book']);
        $assignments = (clone $query)->whereDate('date', $date)->orderBy('position')->orderBy('id')->get();
        $unfinished = (clone $query)->whereDate('date', '<', today())->where('status', '!=', 'approved')->orderBy('date')->orderBy('position')->orderBy('id')->get();
        $pending = (clone $query)->where('status', 'submitted')->orderBy('submitted_at')->orderBy('id')->get();
        $activities = Activity::where(fn ($query) => $query->where('user_id', $student->id)->orWhereHas('assignment', fn ($query) => $query->where('student_id', $student->id)))->orderByDesc('id')->limit(20)->get();
        $currentId = $student->fresh()->current_assignment_id;
        $current = $currentId ? Assignment::where('student_id', $student->id)->with(['photos', 'book'])->find($currentId) : null;

        return response()->json(['data' => [
            'date' => $date,
            'today' => today()->toDateString(),
            'timezone' => config('app.timezone'),
            'student' => $student->only(['id', 'name', 'email', 'role']),
            'assignments' => StudentAssignmentResource::collection($assignments),
            'unfinished' => StudentAssignmentResource::collection($unfinished),
            'awaiting_review' => StudentAssignmentResource::collection($pending),
            'current_assignment' => $current ? new StudentAssignmentResource($current) : null,
            'progress' => ['total' => $assignments->count(), 'submitted' => $assignments->where('status', 'submitted')->count(), 'approved' => $assignments->where('status', 'approved')->count()],
            'routine' => DailyRoutine::report($student, $date),
            'unread' => Message::where('student_id', $student->id)->where('sender_id', '!=', $request->user()->id)->whereNull('read_at')->count(),
            'activities' => $activities->map(fn (Activity $activity): array => $activity->only(['id', 'assignment_id', 'kind', 'description', 'created_at'])),
        ]]);
    }

    public function routine(Request $request): JsonResponse
    {
        return response()->json(['data' => DailyRoutine::report($this->student($request), $this->date($request))]);
    }

    public function transition(Request $request): JsonResponse
    {
        $data = $request->validate(['action' => 'required|in:day-start,day-finish,activity-stop,break,resume', 'version' => 'required|integer|min:0']);
        DailyRoutine::transition($request->user(), $data['action'], (int) $data['version']);

        return response()->json(['data' => DailyRoutine::state($request->user())]);
    }

    public function finish(Request $request): JsonResponse
    {
        $query = Assignment::where('student_id', $request->user()->id)->where('is_extra_practice', false);
        $remaining = (clone $query)->whereDate('date', '<=', today())->whereNotIn('status', ['submitted', 'approved'])->with(['photos', 'book'])->orderBy('date')->orderBy('position')->orderBy('id')->get();

        return response()->json(['data' => ['remaining' => StudentAssignmentResource::collection($remaining), 'awaiting_review_count' => (clone $query)->where('status', 'submitted')->count(), 'routine' => DailyRoutine::report($request->user(), today()->toDateString())]]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $student = $this->student($request);
        if ($request->user()->role === 'student') {
            $student->update(['last_seen_at' => now()]);
        }
        $messages = Message::where('student_id', $student->id);
        $latest = Activity::where(fn ($query) => $query->where('user_id', $student->id)->orWhereHas('assignment', fn ($query) => $query->where('student_id', $student->id)))->max('id') ?? 0;

        return response()->json(['data' => [
            'last_seen' => $student->last_seen_at?->toIso8601String(),
            'on_break' => $student->on_break,
            'unread' => (clone $messages)->where('sender_id', '!=', $request->user()->id)->whereNull('read_at')->count(),
            'submitted' => Assignment::where('student_id', $student->id)->where('is_extra_practice', false)->where('status', 'submitted')->count(),
            'read_through' => (clone $messages)->where('sender_id', $request->user()->id)->whereNotNull('read_at')->max('id') ?? 0,
            'routine' => DailyRoutine::state($student),
            'latest' => $latest,
        ]]);
    }
}
