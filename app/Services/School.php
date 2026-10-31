<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Recurrence;
use App\Models\User;
use Carbon\Carbon;

class School
{
    public static function record(User $user, string $kind, string $description, ?Assignment $assignment = null): void
    {
        Activity::create(['user_id' => $user->id, 'kind' => $kind, 'description' => $description, 'assignment_id' => $assignment?->id]);
    }

    public static function generate(string $date): void
    {
        $day = Carbon::parse($date);
        foreach (Recurrence::where('active', true)->whereDate('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))->get() as $rule) {
            if (in_array($day->dayOfWeekIso, $rule->weekdays)) {
                Assignment::firstOrCreate(['recurrence_id' => $rule->id, 'date' => $day->toDateString()], array_merge($rule->assignment_data, ['student_id' => $rule->student_id]));
            }
        }
    }
}
