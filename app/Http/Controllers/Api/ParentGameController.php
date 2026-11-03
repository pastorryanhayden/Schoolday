<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentGameResource;
use App\Models\Game;
use App\Services\GameLibrary;
use App\Services\ParentActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ParentGameController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return StudentGameResource::collection(Game::where('student_id', ParentActions::student()->id)->withCount('rounds')->with(['rounds' => fn ($query) => $query->orderByDesc('id')->limit(1)])->orderByDesc('active')->orderBy('title')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        return (new StudentGameResource(GameLibrary::save($request->user(), $request->all())))->response()->setStatusCode(201);
    }

    public function update(Request $request, Game $game): StudentGameResource
    {
        return new StudentGameResource(GameLibrary::save($request->user(), $request->all(), $game->id));
    }
}
