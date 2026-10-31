<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Services\DailyRoutine;
use App\Services\PracticeGame;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PracticeController extends Controller
{
    public function show(Request $request, Assignment $assignment): View
    {
        PracticeGame::access($request->user(), $assignment);

        return view($assignment->activity_type === 'spelling' ? 'spelling' : 'practice', ['a' => $assignment, 'summary' => PracticeGame::report($assignment), 'facts' => $request->user()->role === 'parent' && $assignment->activity_type === 'multiplication' ? PracticeGame::facts($assignment->student) : null, 'routine' => DailyRoutine::report($assignment->student, today()->toDateString())]);
    }

    public function act(Request $request, Assignment $assignment): JsonResponse
    {
        return response()->json(PracticeGame::act($request->user(), $assignment, $request->only(['action', 'version', 'attempt_id', 'answer', 'response_ms', 'feedback_ms'])));
    }
}
