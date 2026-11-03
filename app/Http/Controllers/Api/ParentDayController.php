<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\StudentAssignmentResource;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\SchoolDay;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\DailyRoutine;
use App\Services\ParentActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentDayController extends StudentDayController
{
    protected function student(Request $request): User
    {
        return ParentActions::student();
    }

    public function reviews(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1', 'limit' => 'nullable|integer|min:1|max:100']);
        $page = Assignment::where('student_id', $this->student($request)->id)->where('is_extra_practice', false)->where('status', 'submitted')->with(['photos', 'book'])->orderBy('submitted_at')->orderBy('id')->simplePaginate($data['limit'] ?? 100);

        return response()->json(['data' => StudentAssignmentResource::collection($page->getCollection()), 'meta' => ['has_more' => $page->hasMorePages(), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null]]);
    }

    public function activity(Request $request): JsonResponse
    {
        $data = $request->validate(['after' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:100']);
        $student = $this->student($request);
        $query = Activity::where(fn ($query) => $query->where('user_id', $student->id)->orWhere('user_id', $request->user()->id)->orWhereHas('assignment', fn ($query) => $query->where('student_id', $student->id)));
        if (isset($data['after'])) {
            $query->where('id', '>', $data['after'])->orderBy('id');
        } else {
            $query->orderByDesc('id');
        }
        $limit = $data['limit'] ?? 100;
        $activities = $query->limit($limit + 1)->get();
        $hasMore = $activities->count() > $limit;
        $activities = $activities->take($limit)->sortBy('id')->values();

        return response()->json(['data' => $activities->map(fn (Activity $activity): array => $activity->only(['id', 'user_id', 'assignment_id', 'kind', 'description', 'created_at'])), 'meta' => ['has_more' => $hasMore, 'next_after' => $activities->last()?->id ?? ($data['after'] ?? 0)]]);
    }

    public function correct(Request $request, SchoolDay $day, ?WorkSession $session = null): JsonResponse
    {
        DailyRoutine::correct($request->user(), $day->id, $session?->id, $request->all());

        return response()->json(['data' => DailyRoutine::report($this->student($request), $day->date->toDateString())]);
    }

    public function correctDay(Request $request, SchoolDay $day): JsonResponse
    {
        DailyRoutine::correctDay($request->user(), $day->id, $request->all());

        return response()->json(['data' => DailyRoutine::report($this->student($request), $day->date->toDateString())]);
    }
}
