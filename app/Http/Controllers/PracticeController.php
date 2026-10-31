<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Services\DailyRoutine;
use App\Services\MultiplicationPractice;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PracticeController extends Controller
{
    public function show(Request $request, Assignment $assignment): View
    {
        MultiplicationPractice::access($request->user(), $assignment);

        return view('practice', ['a' => $assignment, 'summary' => MultiplicationPractice::summary($assignment), 'facts' => $request->user()->role === 'parent' ? MultiplicationPractice::facts($assignment->student) : null, 'routine' => DailyRoutine::report($assignment->student, today()->toDateString())]);
    }

    public function act(Request $request, Assignment $assignment): JsonResponse
    {
        return response()->json(MultiplicationPractice::act($request->user(), $assignment, $request->only(['action', 'version', 'attempt_id', 'answer', 'response_ms', 'feedback_ms'])));
    }
}
