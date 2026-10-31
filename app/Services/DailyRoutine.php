<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\SchoolDay;
use App\Models\TimelineCorrection;
use App\Models\User;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DailyRoutine
{
    public static function lock(User $student, ?int $version = null): User
    {
        // Write first: SQLite serializes writers; row locks also protect other supported databases.
        $query = User::whereKey($student->id);
        if ($version !== null) {
            $query->where('routine_version', $version);
        }
        abort_unless($query->increment('routine_version') === 1, 409, 'The activity changed on another page or device. Refresh before trying again.');

        return $student->fresh();
    }

    public static function openDay(User $student): ?SchoolDay
    {
        return SchoolDay::where('student_id', $student->id)->whereNull('finished_at')->first();
    }

    public static function active(?SchoolDay $day): ?WorkSession
    {
        return $day?->sessions()->whereNull('ended_at')->first();
    }

    private static function close(SchoolDay $day): void
    {
        $day->sessions()->whereNull('ended_at')->update(['ended_at' => now()]);
    }

    private static function session(SchoolDay $day, string $kind, ?Assignment $assignment = null): void
    {
        $day->sessions()->create(['kind' => $kind, 'assignment_id' => $assignment?->id, 'label' => $assignment ? $assignment->subject.' · '.$assignment->title : ($kind === 'break' ? 'Break' : 'Choosing the next activity'), 'started_at' => now()]);
    }

    public static function transition(User $actor, string $action, int $version, ?int $assignmentId = null): void
    {
        abort_unless($actor->role === 'student', 403);
        DB::transaction(function () use ($actor, $action, $version, $assignmentId): void {
            $student = self::lock($actor, $version);
            $day = self::openDay($student);
            if ($action === 'day-start') {
                abort_if($day !== null, 409, 'Your schoolday is already open. Resume it or finish it first.');
                $day = SchoolDay::firstOrCreate(['student_id' => $student->id, 'date' => today()->toDateString()], ['started_at' => now()]);
                $reopened = $day->finished_at !== null;
                $day->update(['finished_at' => null]);
                self::session($day, 'idle');
                $student->update(['on_break' => false, 'current_assignment_id' => null]);
                School::record($student, 'day_start', $reopened ? 'Reopened the schoolday' : 'Started the schoolday');

                return;
            }
            abort_unless($day !== null, 409, 'Start your schoolday before starting an activity or break.');
            $active = self::active($day);
            if ($action === 'activity-start') {
                $assignment = Assignment::where('student_id', $student->id)->findOrFail($assignmentId);
                abort_unless(in_array($assignment->status, ['not_started', 'in_progress', 'needs_changes']), 409, 'This work is already submitted. Wait for Dad’s review.');
                abort_if($assignment->date->gt(today()), 409, 'Choose work scheduled for today or an earlier day.');
                if ($active?->kind === 'work' && $active->assignment_id === $assignment->id) {
                    return;
                }
                self::close($day);
                self::session($day, 'work', $assignment);
                $assignment->update(['status' => $assignment->status === 'needs_changes' ? 'needs_changes' : 'in_progress', 'started_at' => $assignment->started_at ?? now()]);
                $student->update(['on_break' => false, 'current_assignment_id' => $assignment->id]);
                School::record($student, 'start', 'Started '.$assignment->title, $assignment);

                return;
            }
            if ($action === 'break') {
                abort_if($active?->kind === 'break', 409, 'You are already on a break.');
                self::close($day);
                self::session($day, 'break');
                $student->update(['on_break' => true]);
                School::record($student, 'break', 'Started a break');

                return;
            }
            if ($action === 'resume') {
                abort_unless($active?->kind === 'break', 409, 'There is no break to resume. Refresh your current activity.');
                $assignment = Assignment::where('student_id', $student->id)->whereKey($student->current_assignment_id)->whereIn('status', ['in_progress', 'needs_changes'])->first();
                self::close($day);
                self::session($day, $assignment ? 'work' : 'idle', $assignment);
                $student->update(['on_break' => false, 'current_assignment_id' => $assignment?->id]);
                School::record($student, 'resume', 'Resumed after a break', $assignment);

                return;
            }
            abort_unless(in_array($action, ['activity-stop', 'day-finish']), 422);
            self::close($day);
            if ($action === 'day-finish') {
                $day->update(['finished_at' => now()]);
                School::record($student, 'day_finish', 'Finished the schoolday; unfinished work stays on the plan');
            } else {
                self::session($day, 'idle');
                School::record($student, 'activity_stop', 'Stopped the current activity; unfinished work stays on the plan');
            }
            $student->update(['on_break' => false, 'current_assignment_id' => null]);
        }, 3);
    }

    public static function submitted(User $student, Assignment $assignment): void
    {
        $day = self::openDay($student);
        $active = self::active($day);
        if ($day && $student->current_assignment_id === $assignment->id) {
            if ($active?->assignment_id === $assignment->id) {
                self::close($day);
                self::session($day, 'idle');
            }
            $student->update(['current_assignment_id' => null]);
        }
    }

    public static function state(User $student): array
    {
        $student = $student->fresh();
        $day = self::openDay($student);
        $active = self::active($day);

        return ['version' => $student->routine_version, 'day' => $day?->toArray(), 'current' => $active?->toArray(), 'label' => $day ? ($active?->label ?? 'Choosing the next activity') : 'Schoolday is not running'];
    }

    public static function report(User $student, string $date): array
    {
        $student = $student->fresh();
        $day = SchoolDay::where('student_id', $student->id)->where('date', $date)->with(['sessions', 'corrections'])->first();
        $totals = ['work' => 0, 'break' => 0, 'idle' => 0];
        $byAssignment = [];
        foreach ($day?->sessions ?? [] as $session) {
            $totals[$session->kind] += $session->seconds();
            if ($session->kind === 'work') {
                $key = $session->assignment_id;
                $byAssignment[$key] ??= ['assignment_id' => $key, 'label' => $session->label, 'seconds' => 0];
                $byAssignment[$key]['seconds'] += $session->seconds();
            }
        }
        $open = self::openDay($student);
        $active = self::active($open);

        return ['date' => $date, 'version' => $student->routine_version, 'day' => $day?->toArray(), 'open_day' => $open?->toArray(), 'current' => $active?->toArray(), 'totals_seconds' => $totals, 'by_assignment' => array_values($byAssignment), 'meaning' => 'Reported work periods, not verified attention. Open periods include time until explicitly stopped. Device contact never changes this timeline.'];
    }

    public static function correct(User $parent, int $dayId, ?int $sessionId, array $data): void
    {
        ParentActions::authorize($parent);
        $data = Validator::make($data, ['version' => 'required|integer|min:0', 'started_at' => 'required|date', 'ended_at' => 'required|date|after:started_at', 'reason' => 'required|string|max:1000', 'kind' => 'nullable|in:work,break,idle', 'assignment_id' => 'nullable|integer'])->validate();
        DB::transaction(function () use ($parent, $dayId, $sessionId, $data): void {
            $student = self::lock(ParentActions::student(), (int) $data['version']);
            $day = SchoolDay::where('student_id', $student->id)->findOrFail($dayId);
            $session = $sessionId ? $day->sessions()->findOrFail($sessionId) : null;
            $start = Carbon::parse($data['started_at'])->timezone(config('app.timezone'));
            $end = Carbon::parse($data['ended_at'])->timezone(config('app.timezone'));
            abort_if($start->lt($day->started_at) || $end->gt($day->finished_at ?? now()), 422, 'Use times inside this schoolday and no later than now.');
            $overlap = $day->sessions()->when($session, fn ($query) => $query->where('id', '!=', $session->id))->where('started_at', '<', $end)->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $start))->exists();
            abort_if($overlap, 422, 'These times overlap another period. Shorten that period first.');
            $kind = $data['kind'] ?? $session?->kind;
            abort_unless(in_array($kind, ['work', 'break', 'idle']), 422, 'Choose a period type.');
            $assignmentId = array_key_exists('assignment_id', $data) ? $data['assignment_id'] : $session?->assignment_id;
            $assignment = $kind === 'work' ? Assignment::where('student_id', $student->id)->findOrFail($assignmentId) : null;
            $before = $session?->toArray() ?? [];
            $wasOpen = $session && $session->ended_at === null;
            $values = ['kind' => $kind, 'assignment_id' => $assignment?->id, 'label' => $assignment ? $assignment->subject.' · '.$assignment->title : ($kind === 'break' ? 'Break' : 'Choosing the next activity'), 'started_at' => $start, 'ended_at' => $end];
            if ($session) {
                $session->update($values);
            } else {
                $session = $day->sessions()->create($values);
            }
            if ($wasOpen) {
                $student->update(['on_break' => false, 'current_assignment_id' => null]);
            }
            TimelineCorrection::create(['school_day_id' => $day->id, 'work_session_id' => $session->id, 'editor_id' => $parent->id, 'before' => $before, 'after' => $session->fresh()->toArray(), 'reason' => $data['reason']]);
            School::record($parent, 'timeline_correction', 'Corrected reported time: '.$data['reason']);
        }, 3);
    }

    public static function correctDay(User $parent, int $dayId, array $data): void
    {
        ParentActions::authorize($parent);
        $data = Validator::make($data, ['version' => 'required|integer|min:0', 'started_at' => 'required|date', 'finished_at' => 'nullable|date|after:started_at', 'reason' => 'required|string|max:1000'])->validate();
        DB::transaction(function () use ($parent, $dayId, $data): void {
            $student = self::lock(ParentActions::student(), (int) $data['version']);
            $day = SchoolDay::where('student_id', $student->id)->findOrFail($dayId);
            $start = Carbon::parse($data['started_at'])->timezone(config('app.timezone'));
            $finish = empty($data['finished_at']) ? null : Carbon::parse($data['finished_at'])->timezone(config('app.timezone'));
            abort_if($start->toDateString() !== $day->date->toDateString() || $start->gt(now()) || $finish?->gt(now()), 422, 'The start must stay on this schoolday’s date; times cannot be in the future.');
            abort_if($day->finished_at !== null && $finish === null, 422, 'Use Start my day to reopen today. A correction must keep a finished day closed.');
            abort_if($day->sessions()->where('started_at', '<', $start)->exists() || ($finish && $day->sessions()->where(fn ($query) => $query->where('started_at', '>', $finish)->orWhere('ended_at', '>', $finish))->exists()), 422, 'Adjust periods outside the new day boundaries first.');
            $overlap = SchoolDay::where('student_id', $student->id)->where('id', '!=', $day->id)->when($finish, fn ($query) => $query->where('started_at', '<', $finish))->where(fn ($query) => $query->whereNull('finished_at')->orWhere('finished_at', '>', $start))->exists();
            abort_if($overlap, 422, 'These day boundaries overlap another schoolday.');
            $before = $day->toArray();
            if ($finish) {
                $day->sessions()->whereNull('ended_at')->update(['ended_at' => $finish]);
                if ($day->finished_at === null) {
                    $student->update(['on_break' => false, 'current_assignment_id' => null]);
                }
            }
            $day->update(['started_at' => $start, 'finished_at' => $finish]);
            TimelineCorrection::create(['school_day_id' => $day->id, 'editor_id' => $parent->id, 'before' => $before, 'after' => $day->fresh()->toArray(), 'reason' => $data['reason']]);
            School::record($parent, 'timeline_correction', 'Corrected schoolday boundaries: '.$data['reason']);
        }, 3);
    }
}
