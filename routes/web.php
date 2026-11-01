<?php

use App\Http\Controllers\BookController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\PracticeController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\SchoolController as School;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [School::class, 'login'])->middleware('throttle:6,1');
});
Route::middleware('auth')->group(function () {
    Route::get('/', [School::class, 'home'])->name('home');
    Route::post('/routine', [RoutineController::class, 'transition'])->name('routine.transition');
    Route::get('/routine/finish', [RoutineController::class, 'finish'])->name('routine.finish');
    Route::post('/parent/timeline/{day}/periods', [RoutineController::class, 'correct'])->name('timeline.add');
    Route::post('/parent/timeline/{day}/periods/{session}', [RoutineController::class, 'correct'])->name('timeline.correct');
    Route::post('/parent/timeline/{day}', [RoutineController::class, 'correctDay'])->name('timeline.day');
    Route::post('/logout', [School::class, 'logout']);
    Route::get('/games', [GameController::class, 'index'])->name('games');
    Route::post('/games/{game}/play', [GameController::class, 'play'])->middleware('throttle:30,1')->name('games.play');
    Route::post('/parent/games', [GameController::class, 'save'])->name('games.store');
    Route::post('/parent/games/{game}', [GameController::class, 'save'])->name('games.update');
    Route::get('/practice/{assignment}', [PracticeController::class, 'show'])->name('practice.show');
    Route::post('/practice/{assignment}', [PracticeController::class, 'act'])->middleware('throttle:180,1')->name('practice.act');
    Route::get('/assignments/{assignment}', [School::class, 'assignment'])->name('assignment');
    Route::post('/assignments/{assignment}/start', [School::class, 'start']);
    Route::post('/assignments/{assignment}/draft', [School::class, 'draft']);
    Route::post('/assignments/{assignment}/photos', [School::class, 'upload'])->middleware('throttle:30,1');
    Route::post('/assignments/{assignment}/submit', [School::class, 'submit']);
    Route::post('/assignments/{assignment}/review', [School::class, 'review']);
    Route::get('/photos/{photo}', [School::class, 'photo'])->name('photo');
    Route::delete('/photos/{photo}', [School::class, 'removePhoto']);
    Route::get('/parent/settings', [BookController::class, 'index'])->name('settings');
    Route::post('/parent/books', [BookController::class, 'save'])->name('books.store');
    Route::post('/parent/books/{book}', [BookController::class, 'save'])->name('books.update');
    Route::post('/parent/assignments', [School::class, 'save']);
    Route::post('/parent/assignments/{assignment}', [School::class, 'save']);
    Route::post('/parent/copy', [School::class, 'copy']);
    Route::post('/parent/recurrences', [School::class, 'recurrence']);
    Route::post('/parent/recurrences/{recurrence}/stop', [School::class, 'stopRecurrence']);
    Route::post('/parent/reorder', [School::class, 'reorder']);
    Route::post('/messages', [School::class, 'message'])->middleware('throttle:30,1');
    Route::post('/messages/read', [School::class, 'read']);
    Route::post('/heartbeat', [School::class, 'heartbeat']);
    Route::post('/break', [School::class, 'pause']);
});
