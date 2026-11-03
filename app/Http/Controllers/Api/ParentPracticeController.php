<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentAssignmentResource;
use App\Models\Assignment;
use App\Services\ParentActions;
use App\Services\PracticeGame;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentPracticeController extends Controller
{
    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        $assignment = ParentActions::assignment($request->user(), $assignment->id);
        PracticeGame::access($request->user(), $assignment);

        return response()->json(['data' => ['assignment' => new StudentAssignmentResource($assignment->load(['photos', 'book'])), 'report' => PracticeGame::report($assignment), 'facts' => $assignment->activity_type === 'multiplication' ? PracticeGame::facts(ParentActions::student()) : null]]);
    }

    public function facts(): JsonResponse
    {
        return response()->json(['data' => PracticeGame::facts(ParentActions::student())]);
    }
}
