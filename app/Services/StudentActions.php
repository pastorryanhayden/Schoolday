<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Message;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StudentActions
{
    public static function access(User $student, Assignment $assignment): void
    {
        abort_unless($student->role === 'student' && $assignment->student_id === $student->id, 403);
    }

    public static function editable(Assignment $assignment): void
    {
        abort_unless(in_array($assignment->status, ['not_started', 'in_progress', 'needs_changes']), 409, 'This work is already submitted. Wait for Dad’s review.');
    }

    public static function draft(User $student, Assignment $assignment, array $data): void
    {
        self::access($student, $assignment);
        $data = Validator::make($data, ['note' => 'nullable|string|max:4000'])->validate();
        DB::transaction(function () use ($assignment, $data): void {
            $fresh = Assignment::lockForUpdate()->findOrFail($assignment->id);
            self::editable($fresh);
            $fresh->update($data);
        });
    }

    public static function upload(User $student, Assignment $assignment, mixed $file): Photo
    {
        self::access($student, $assignment);
        self::editable($assignment);
        Validator::make(['photo' => $file], ['photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240|dimensions:max_width=10000,max_height=10000'])->validate();
        abort_if($assignment->photos()->count() >= 20, 422, 'Limit of 20 photos per assignment.');
        $path = $file->store('worksheets', 'local');
        abort_unless($path, 503, 'Photo storage failed. Please retry.');
        try {
            return DB::transaction(function () use ($assignment, $path, $file): Photo {
                $fresh = Assignment::lockForUpdate()->findOrFail($assignment->id);
                self::editable($fresh);
                abort_if($fresh->photos()->count() >= 20, 422, 'Limit of 20 photos per assignment.');

                return $fresh->photos()->create(['path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 200), 'revision' => $fresh->revision + 1]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public static function removePhoto(User $student, Photo $photo): void
    {
        $assignment = Assignment::findOrFail($photo->assignment_id);
        self::access($student, $assignment);
        self::editable($assignment);
        DB::transaction(function () use ($assignment, $photo): void {
            $fresh = Assignment::lockForUpdate()->findOrFail($assignment->id);
            self::editable($fresh);
            abort_unless($photo->revision > $fresh->revision, 409, 'Photos from a submitted revision are preserved.');
            $photo->delete();
        });
        Storage::disk('local')->delete($photo->path);
    }

    public static function submit(User $student, Assignment $assignment, array $data): void
    {
        self::access($student, $assignment);
        abort_if($assignment->isPractice(), 409, 'Complete the practice target in the game to submit it for review.');
        $data = Validator::make($data, ['note' => 'nullable|string|max:4000'])->validate();
        DB::transaction(function () use ($student, $assignment, $data): void {
            $student = DailyRoutine::lock($student);
            $fresh = Assignment::lockForUpdate()->findOrFail($assignment->id);
            self::editable($fresh);
            if ($fresh->requires_photo && ! $fresh->photos()->exists()) {
                throw ValidationException::withMessages(['photo' => 'Add at least one worksheet photo before submitting.']);
            }
            DailyRoutine::submitted($student, $fresh);
            $kind = $fresh->revision ? 'resubmission' : 'submission';
            $fresh->update(['status' => 'submitted', 'note' => $data['note'] ?? $fresh->note, 'submitted_at' => now(), 'revision' => $fresh->revision + 1]);
            School::record($student, $kind, ucfirst($kind).': '.$fresh->title.' (revision '.$fresh->revision.')'.($fresh->note ? ' — '.$fresh->note : ''), $fresh);
        });
    }

    public static function message(User $student, array $data): Message
    {
        abort_unless($student->role === 'student', 403);
        $data = Validator::make($data, ['body' => 'required|string|max:4000', 'assignment_id' => 'nullable|integer|exists:assignments,id', 'help' => 'nullable|boolean'])->validate();
        $data['help'] = (bool) ($data['help'] ?? false);
        $assignment = empty($data['assignment_id']) ? null : Assignment::findOrFail($data['assignment_id']);
        if ($assignment) {
            self::access($student, $assignment);
        }

        return DB::transaction(function () use ($student, $data, $assignment): Message {
            $message = Message::create($data + ['sender_id' => $student->id, 'student_id' => $student->id]);
            School::record($student, ! empty($data['help']) ? 'help' : 'message', ! empty($data['help']) ? 'Asked Dad for help' : 'Sent a message', $assignment);

            return $message;
        });
    }
}
