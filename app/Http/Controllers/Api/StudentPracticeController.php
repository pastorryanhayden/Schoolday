<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentAssignmentResource;
use App\Http\Resources\StudentGameResource;
use App\Models\Assignment;
use App\Models\Game;
use App\Services\DailyRoutine;
use App\Services\GameLibrary;
use App\Services\PracticeGame;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentPracticeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $games = Game::where('student_id', $request->user()->id)->where('active', true)->withCount('rounds')->with(['rounds' => fn ($query) => $query->orderByDesc('id')->limit(1)])->orderBy('title')->orderBy('id')->get();

        return response()->json(['data' => StudentGameResource::collection($games), 'meta' => ['routine' => DailyRoutine::state($request->user())]]);
    }

    public function play(Request $request, Game $game): JsonResponse
    {
        abort_unless($game->student_id === $request->user()->id, 404);
        $data = $request->validate(['version' => 'required|integer|min:0']);
        $assignment = GameLibrary::play($request->user(), $game, (int) $data['version']);

        return response()->json(['data' => ['assignment' => new StudentAssignmentResource($assignment->load(['photos', 'book'])), 'routine' => DailyRoutine::state($request->user())]], 201);
    }

    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        abort_unless($assignment->student_id === $request->user()->id, 404);
        PracticeGame::access($request->user(), $assignment);

        return response()->json(['data' => ['assignment' => new StudentAssignmentResource($assignment->load(['photos', 'book'])), 'practice' => PracticeGame::snapshot($request->user(), $assignment), 'report' => PracticeGame::report($assignment)]]);
    }

    public function act(Request $request, Assignment $assignment): JsonResponse
    {
        abort_unless($assignment->student_id === $request->user()->id, 404);

        return response()->json(['data' => PracticeGame::act($request->user(), $assignment, $request->only(['action', 'version', 'attempt_id', 'answer', 'response_ms', 'feedback_ms']))]);
    }
}
