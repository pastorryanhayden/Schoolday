<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\StudentAssignmentResource;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\User;
use App\Services\ParentActions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ParentAssignmentController extends StudentAssignmentController
{
    protected function student(Request $request): User
    {
        return ParentActions::student();
    }

    public function show(Request $request, Assignment $assignment): StudentAssignmentResource
    {
        $this->own($request, $assignment);
        $history = Activity::where('assignment_id', $assignment->id)->orderBy('id')->get()->map(fn (Activity $activity): array => $activity->only(['id', 'user_id', 'kind', 'description', 'created_at']));

        return parent::show($request, $assignment)->additional(['meta' => ['history' => $history]]);
    }

    public function store(Request $request): JsonResponse
    {
        $assignment = ParentActions::create($request->user(), $request->all());

        return (new StudentAssignmentResource($assignment->load(['photos', 'book'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, Assignment $assignment): StudentAssignmentResource
    {
        $this->own($request, $assignment);
        $assignment = ParentActions::update($request->user(), $assignment->id, $request->all());

        return new StudentAssignmentResource($assignment->load(['photos', 'book']));
    }

    public function review(Request $request, Assignment $assignment): StudentAssignmentResource
    {
        $this->own($request, $assignment);
        $data = $request->validate(['revision' => 'required|integer|min:1', 'decision' => 'required|in:approved,needs_changes', 'feedback' => 'nullable|required_if:decision,needs_changes|string|max:4000']);
        $assignment = ParentActions::review($request->user(), $assignment->id, $data['decision'], $data['feedback'] ?? null, (int) $data['revision']);

        return new StudentAssignmentResource($assignment->load(['photos', 'book']));
    }

    public function copy(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|different:from']);
        $assignments = ParentActions::copy($request->user(), $data['from'], $data['to']);

        return response()->json(['data' => StudentAssignmentResource::collection((new Collection($assignments))->load(['photos', 'book']))], 201);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'ids' => 'required|array|min:1', 'ids.*' => 'required|integer|distinct']);
        $ids = ParentActions::reorder($request->user(), $data['date'], $data['ids']);

        return response()->json(['data' => ['ids' => $ids]]);
    }

    public function plan(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'assignments' => 'required|array|list|min:1|max:100', 'assignments.*' => 'required|array']);
        $assignments = DB::transaction(function () use ($request, $data): array {
            $position = Assignment::where('student_id', $this->student($request)->id)->where('is_extra_practice', false)->whereDate('date', $data['date'])->max('position') ?? -1;

            return collect($data['assignments'])->map(function (array $item) use ($request, $data, &$position): Assignment {
                $assignment = ParentActions::create($request->user(), ['date' => $data['date']] + $item + ['position' => $position + 1]);
                $position = max($position, $assignment->position);

                return $assignment;
            })->all();
        });

        return response()->json(['data' => StudentAssignmentResource::collection((new Collection($assignments))->load(['photos', 'book']))], 201);
    }
}
