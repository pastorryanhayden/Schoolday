<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\SchoolDay;
use App\Models\WorkSession;
use App\Services\DailyRoutine;
use App\Services\ParentActions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RoutineController extends Controller
{
    public function transition(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'student', 403);
        $data = $request->validate(['action' => 'required|in:day-start,day-finish,activity-stop,break,resume', 'version' => 'required|integer|min:0']);
        DailyRoutine::transition($request->user(), $data['action'], (int) $data['version']);

        return redirect()->route('home')->with('success', match ($data['action']) {
            'day-start' => 'Your schoolday has started. Choose an activity.',
            'day-finish' => 'Schoolday finished. Any unfinished work stays on your plan.',
            'activity-stop' => 'Activity stopped. Choose your next step.',
            'break' => 'Break started. Resume when you return.',
            'resume' => 'Welcome back. Your activity has resumed.',
        });
    }

    public function finish(Request $request): View
    {
        abort_unless($request->user()->role === 'student', 403);
        $routine = DailyRoutine::report($request->user(), today()->toDateString());
        $remaining = Assignment::where('is_extra_practice', false)->where('student_id', $request->user()->id)->whereDate('date', '<=', today())->whereNotIn('status', ['submitted', 'approved'])->orderBy('date')->orderBy('position')->get();
        $pending = Assignment::where('is_extra_practice', false)->where('student_id', $request->user()->id)->where('status', 'submitted')->count();

        return view('finish-day', compact('routine', 'remaining', 'pending'));
    }

    public function correct(Request $request, SchoolDay $day, ?WorkSession $session = null): RedirectResponse
    {
        ParentActions::authorize($request->user());
        DailyRoutine::correct($request->user(), $day->id, $session?->id, $request->only(['version', 'started_at', 'ended_at', 'reason', 'kind', 'assignment_id']));

        return redirect('/?date='.$day->date->toDateString().'#activity')->with('success', 'Timeline corrected. Original values and the reason are preserved.');
    }

    public function correctDay(Request $request, SchoolDay $day): RedirectResponse
    {
        ParentActions::authorize($request->user());
        DailyRoutine::correctDay($request->user(), $day->id, $request->only(['version', 'started_at', 'finished_at', 'reason']));

        return redirect('/?date='.$day->date->toDateString().'#activity')->with('success', 'Schoolday corrected. Original values and the reason are preserved.');
    }
}
