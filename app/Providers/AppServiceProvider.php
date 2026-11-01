<?php

namespace App\Providers;

use App\Models\Game;
use App\Models\User;
use App\Services\DailyRoutine;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layout', function ($view): void {
            $actor = auth()->user();
            $student = $actor?->role === 'student' ? $actor : ($actor ? User::where('role', 'student')->first() : null);
            $view->with('routineState', $student ? DailyRoutine::state($student) : null);
            $view->with('libraryGames', $student ? Game::where('student_id', $student->id)->where('active', true)->orderBy('title')->get() : collect());
        });
    }
}
