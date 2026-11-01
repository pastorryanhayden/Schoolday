<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\DailyRoutine;
use App\Services\GameLibrary;
use App\Services\ParentActions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function index(Request $request): View
    {
        $student = $request->user()->role === 'student' ? $request->user() : ParentActions::student();
        $games = Game::where('student_id', $student->id)->when($request->user()->role === 'student', fn ($query) => $query->where('active', true))->withCount('rounds')->with(['rounds' => fn ($query) => $query->latest('id')->limit(1)])->orderByDesc('active')->orderBy('title')->get();

        return view('games', ['games' => $games, 'parent' => $request->user()->role === 'parent', 'routineState' => DailyRoutine::state($student)]);
    }

    public function save(Request $request, ?Game $game = null): RedirectResponse
    {
        GameLibrary::save($request->user(), $request->only(GameLibrary::FIELDS), $game?->id);

        return redirect()->route('games')->with('success', 'Game library updated. Earlier rounds keep their original settings.');
    }

    public function play(Request $request, Game $game): RedirectResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:0']);
        $assignment = GameLibrary::play($request->user(), $game, (int) $data['version']);

        return redirect()->route('practice.show', $assignment);
    }
}
