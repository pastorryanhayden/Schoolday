<?php

use App\Http\Controllers\Api\ParentAssignmentController;
use App\Http\Controllers\Api\ParentAuthController;
use App\Http\Controllers\Api\ParentBookController;
use App\Http\Controllers\Api\ParentDayController;
use App\Http\Controllers\Api\ParentGameController;
use App\Http\Controllers\Api\ParentMessageController;
use App\Http\Controllers\Api\ParentPracticeController;
use App\Http\Controllers\Api\ParentRecurrenceController;
use App\Http\Controllers\Api\StudentAssignmentController;
use App\Http\Controllers\Api\StudentAuthController;
use App\Http\Controllers\Api\StudentDayController;
use App\Http\Controllers\Api\StudentMessageController;
use App\Http\Controllers\Api\StudentPracticeController;
use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/student')->name('api.student.')->group(function (): void {
    Route::post('login', [StudentAuthController::class, 'login'])->middleware('throttle:6,1,student-login:')->name('login');

    Route::middleware([AuthenticateDevice::class.':student', 'throttle:240,1,student-api:'])->group(function (): void {
        Route::get('me', [StudentAuthController::class, 'me'])->name('auth.me');
        Route::post('logout', [StudentAuthController::class, 'logout'])->name('auth.logout');
        Route::post('logout-all', [StudentAuthController::class, 'logoutAll'])->name('auth.logoutAll');
        Route::get('devices', [StudentAuthController::class, 'devices'])->name('auth.devices');
        Route::delete('devices/{deviceToken}', [StudentAuthController::class, 'revoke'])->name('auth.revoke');

        Route::get('day', [StudentDayController::class, 'show'])->name('day.show');
        Route::get('routine', [StudentDayController::class, 'routine'])->name('day.routine');
        Route::post('routine', [StudentDayController::class, 'transition'])->name('day.transition');
        Route::get('routine/finish', [StudentDayController::class, 'finish'])->name('day.finish');
        Route::post('heartbeat', [StudentDayController::class, 'heartbeat'])->name('day.heartbeat');

        Route::get('assignments', [StudentAssignmentController::class, 'index'])->name('assignments.index');
        Route::get('assignments/{assignment}', [StudentAssignmentController::class, 'show'])->name('assignments.show');
        Route::post('assignments/{assignment}/start', [StudentAssignmentController::class, 'start'])->name('assignments.start');
        Route::patch('assignments/{assignment}/draft', [StudentAssignmentController::class, 'draft'])->name('assignments.draft');
        Route::post('assignments/{assignment}/submit', [StudentAssignmentController::class, 'submit'])->name('assignments.submit');
        Route::post('assignments/{assignment}/photos', [StudentAssignmentController::class, 'upload'])->middleware('throttle:30,1,student-photos:')->name('assignments.upload');
        Route::get('photos/{photo}', [StudentAssignmentController::class, 'photo'])->name('photo');
        Route::delete('photos/{photo}', [StudentAssignmentController::class, 'removePhoto'])->name('assignments.removePhoto');

        Route::get('messages', [StudentMessageController::class, 'index'])->name('messages.index');
        Route::post('messages', [StudentMessageController::class, 'store'])->middleware('throttle:30,1,student-messages:')->name('messages.store');
        Route::post('messages/read', [StudentMessageController::class, 'read'])->name('messages.read');

        Route::get('games', [StudentPracticeController::class, 'index'])->name('practice.index');
        Route::post('games/{game}/play', [StudentPracticeController::class, 'play'])->middleware('throttle:30,1,student-games:')->name('practice.play');
        Route::get('practice/{assignment}', [StudentPracticeController::class, 'show'])->name('practice.show');
        Route::post('practice/{assignment}', [StudentPracticeController::class, 'act'])->middleware('throttle:180,1,student-practice:')->name('practice.act');
    });
});

Route::prefix('v1/parent')->name('api.parent.')->group(function (): void {
    Route::post('login', [ParentAuthController::class, 'login'])->middleware('throttle:6,1,parent-login:')->name('login');

    Route::middleware([AuthenticateDevice::class.':parent', 'throttle:240,1,parent-api:'])->group(function (): void {
        Route::get('me', [ParentAuthController::class, 'me'])->name('auth.me');
        Route::post('logout', [ParentAuthController::class, 'logout'])->name('auth.logout');
        Route::post('logout-all', [ParentAuthController::class, 'logoutAll'])->name('auth.logout_all');
        Route::get('devices', [ParentAuthController::class, 'devices'])->name('auth.devices');
        Route::delete('devices/{deviceToken}', [ParentAuthController::class, 'revoke'])->name('auth.revoke');

        Route::get('day', [ParentDayController::class, 'show'])->name('day.show');
        Route::get('reviews', [ParentDayController::class, 'reviews'])->name('reviews');
        Route::get('activity', [ParentDayController::class, 'activity'])->name('activity');
        Route::post('heartbeat', [ParentDayController::class, 'heartbeat'])->name('heartbeat');
        Route::get('timeline', [ParentDayController::class, 'routine'])->name('timeline');
        Route::post('timeline/{day}/periods', [ParentDayController::class, 'correct'])->name('timeline.add');
        Route::patch('timeline/{day}/periods/{session}', [ParentDayController::class, 'correct'])->name('timeline.correct');
        Route::patch('timeline/{day}', [ParentDayController::class, 'correctDay'])->name('timeline.day');

        Route::get('assignments', [ParentAssignmentController::class, 'index'])->name('assignments.index');
        Route::post('assignments', [ParentAssignmentController::class, 'store'])->name('assignments.store');
        Route::get('assignments/{assignment}', [ParentAssignmentController::class, 'show'])->name('assignments.show');
        Route::patch('assignments/{assignment}', [ParentAssignmentController::class, 'update'])->name('assignments.update');
        Route::post('assignments/{assignment}/review', [ParentAssignmentController::class, 'review'])->name('assignments.review');
        Route::post('plan', [ParentAssignmentController::class, 'plan'])->name('plan');
        Route::post('copy', [ParentAssignmentController::class, 'copy'])->name('copy');
        Route::post('reorder', [ParentAssignmentController::class, 'reorder'])->name('reorder');
        Route::get('photos/{photo}', [ParentAssignmentController::class, 'photo'])->name('photo');

        Route::get('messages', [ParentMessageController::class, 'index'])->name('messages.index');
        Route::post('messages', [ParentMessageController::class, 'store'])->middleware('throttle:30,1,parent-messages:')->name('messages.store');
        Route::post('messages/read', [ParentMessageController::class, 'read'])->name('messages.read');

        Route::get('books', [ParentBookController::class, 'index'])->name('books.index');
        Route::post('books', [ParentBookController::class, 'store'])->name('books.store');
        Route::patch('books/{book}', [ParentBookController::class, 'update'])->name('books.update');
        Route::get('games', [ParentGameController::class, 'index'])->name('games.index');
        Route::post('games', [ParentGameController::class, 'store'])->name('games.store');
        Route::patch('games/{game}', [ParentGameController::class, 'update'])->name('games.update');
        Route::get('recurrences', [ParentRecurrenceController::class, 'index'])->name('recurrences.index');
        Route::post('recurrences', [ParentRecurrenceController::class, 'store'])->name('recurrences.store');
        Route::post('recurrences/{recurrence}/stop', [ParentRecurrenceController::class, 'stop'])->name('recurrences.stop');
        Route::get('practice/{assignment}', [ParentPracticeController::class, 'show'])->name('practice.show');
        Route::get('facts', [ParentPracticeController::class, 'facts'])->name('facts');
    });
});
