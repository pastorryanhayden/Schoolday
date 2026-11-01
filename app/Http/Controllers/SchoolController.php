<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Book;
use App\Models\Message;
use App\Models\Photo;
use App\Models\Recurrence;
use App\Models\User;
use App\Services\DailyRoutine;
use App\Services\ParentActions;
use App\Services\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SchoolController extends Controller
{
    private function parent(Request $r): void
    {
        abort_unless($r->user()->role === 'parent', 403);
    }

    private function access(Request $r, Assignment $a): void
    {
        abort_unless($r->user()->role === 'parent' || $a->student_id === $r->user()->id, 403);
    }

    private function student(Request $r, Assignment $a): void
    {
        abort_unless($r->user()->role === 'student' && $a->student_id === $r->user()->id, 403);
    }

    private function editable(Assignment $a): void
    {
        abort_unless(in_array($a->status, ['not_started', 'in_progress', 'needs_changes']), 409, 'This work is already submitted. Wait for Dad’s review.');
    }

    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($data, $r->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        $r->session()->regenerate();

        return redirect('/');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }

    public function home(Request $r)
    {
        $r->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $r->input('date', today()->toDateString());
        School::generate($date);
        $student = $r->user()->role === 'student' ? $r->user() : User::where('role', 'student')->firstOrFail();
        $assignments = Assignment::where('student_id', $student->id)->whereDate('date', $date)->with(['photos', 'book'])->orderBy('position')->orderBy('id')->get();
        $unread = Message::where('student_id', $student->id)->where('sender_id', '!=', $r->user()->id)->whereNull('read_at')->count();
        $messages = Message::where('student_id', $student->id)->with(['sender', 'assignment'])->latest('id')->limit(100)->get()->reverse()->values();
        $activities = Activity::where(fn ($q) => $q->where('user_id', $student->id)->orWhereHas('assignment', fn ($a) => $a->where('student_id', $student->id)))->latest()->limit(20)->get();
        $unfinished = Assignment::where('student_id', $student->id)->whereDate('date', '<', today())->where('status', '!=', 'approved')->orderBy('date')->get();
        $pending = Assignment::where('student_id', $student->id)->where('status', 'submitted')->orderBy('submitted_at')->get();
        $rules = Recurrence::where('student_id', $student->id)->where('active', true)->get();

        $books = Book::where('student_id', $student->id)->where('active', true)->orderBy('subject')->orderBy('title')->get();

        $routine = DailyRoutine::report($student, $date);
        $current = ($routine['current']['kind'] ?? null) === 'work' ? Assignment::where('student_id', $student->id)->find($routine['current']['assignment_id']) : null;
        $timelineAssignments = $r->user()->role === 'parent' ? Assignment::where('student_id', $student->id)->whereDate('date', '<=', $date)->orderBy('date')->orderBy('position')->get() : collect();

        return view('dashboard', compact('date', 'student', 'assignments', 'unread', 'messages', 'activities', 'unfinished', 'current', 'pending', 'rules', 'books', 'routine', 'timelineAssignments'));
    }

    public function assignment(Request $r, Assignment $assignment)
    {
        $this->access($r, $assignment);
        if ($assignment->isPractice()) {
            return redirect()->route('practice.show', $assignment);
        }
        $assignment->load(['photos', 'book']);
        $books = $r->user()->role === 'parent' ? Book::where('student_id', $assignment->student_id)->where(fn ($query) => $query->where('active', true)->orWhere('id', $assignment->book_id))->orderBy('subject')->orderBy('title')->get() : collect();

        return view('assignment', ['a' => $assignment, 'books' => $books, 'routine' => DailyRoutine::report($assignment->student, today()->toDateString())]);
    }

    public function start(Request $r, Assignment $assignment)
    {
        $this->student($r, $assignment);
        $this->editable($assignment);
        $data = $r->validate(['version' => 'required|integer|min:0']);
        DailyRoutine::transition($r->user(), 'activity-start', (int) $data['version'], $assignment->id);

        return $assignment->isPractice() ? redirect()->route('practice.show', $assignment) : redirect()->to(route('assignment', $assignment).'#your-work');
    }

    public function draft(Request $r, Assignment $assignment)
    {
        $this->student($r, $assignment);
        $data = $r->validate(['note' => 'nullable|string|max:4000']);
        DB::transaction(function () use ($assignment, $data) {
            $fresh = Assignment::lockForUpdate()->findOrFail($assignment->id);
            $this->editable($fresh);
            $fresh->update($data);
        });

        return response()->json(['saved' => true]);
    }

    public function upload(Request $r, Assignment $assignment)
    {
        $this->student($r, $assignment);
        $this->editable($assignment);
        $r->validate(['photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240|dimensions:max_width=10000,max_height=10000']);
        abort_if($assignment->photos()->count() >= 20, 422, 'Limit of 20 photos per assignment.');
        $path = $r->file('photo')->store('worksheets', 'local');
        abort_unless($path, 503, 'Photo storage failed. Please retry.');
        try {
            $photo = DB::transaction(function () use ($assignment, $path, $r) {
                $fresh = Assignment::lockForUpdate()->findOrFail($assignment->id);
                $this->editable($fresh);
                abort_if($fresh->photos()->count() >= 20, 422, 'Limit of 20 photos per assignment.');

                return $fresh->photos()->create(['path' => $path, 'name' => mb_substr($r->file('photo')->getClientOriginalName(), 0, 200), 'revision' => $fresh->revision + 1]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return response()->json(['id' => $photo->id, 'url' => route('photo', $photo), 'name' => $photo->name]);
    }

    public function photo(Request $r, Photo $photo)
    {
        $a = Assignment::findOrFail($photo->assignment_id);
        $this->access($r, $a);
        $response = response()->file(Storage::disk('local')->path($photo->path), ['X-Content-Type-Options' => 'nosniff']);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function removePhoto(Request $r, Photo $photo)
    {
        $a = Assignment::findOrFail($photo->assignment_id);
        $this->student($r, $a);
        $this->editable($a);
        DB::transaction(function () use ($a, $photo) {
            $fresh = Assignment::lockForUpdate()->findOrFail($a->id);
            $this->editable($fresh);
            abort_unless($photo->revision > $fresh->revision, 409, 'Photos from a submitted revision are preserved.');
            $photo->delete();
        });
        Storage::disk('local')->delete($photo->path);

        return response()->json(['removed' => true]);
    }

    public function submit(Request $r, Assignment $assignment)
    {
        $this->student($r, $assignment);
        abort_if($assignment->isPractice(), 409, 'Complete the practice target in the game to submit it for review.');
        $data = $r->validate(['note' => 'nullable|string|max:4000']);
        DB::transaction(function () use ($r, $assignment, $data) {
            $student = DailyRoutine::lock($r->user());
            $a = Assignment::lockForUpdate()->findOrFail($assignment->id);
            $this->editable($a);
            if ($a->requires_photo && ! $a->photos()->exists()) {
                throw ValidationException::withMessages(['photo' => 'Add at least one worksheet photo before submitting.']);
            }
            DailyRoutine::submitted($student, $a);
            $kind = $a->revision ? 'resubmission' : 'submission';
            $a->update(['status' => 'submitted', 'note' => $data['note'] ?? $a->note, 'submitted_at' => now(), 'revision' => $a->revision + 1]);
            School::record($r->user(), $kind, ucfirst($kind).': '.$a->title.' (revision '.$a->revision.')'.($a->note ? ' — '.$a->note : ''), $a);
        });

        return redirect()->route('assignment', $assignment)->with('success', 'Assignment finished. Dad will review your work next.');
    }

    public function review(Request $r, Assignment $assignment)
    {
        $this->parent($r);
        $data = $r->validate(['decision' => 'required|in:approved,needs_changes', 'feedback' => 'nullable|required_if:decision,needs_changes|string|max:4000']);
        ParentActions::review($r->user(), $assignment->id, $data['decision'], $data['feedback'] ?? null);

        return back()->with('success', 'Review sent to Noah.');
    }

    private function assignmentData(Request $r): array
    {
        return $r->validate(['spelling_words' => 'nullable|string|max:3000', 'question_target' => 'nullable|integer|min:1|max:100', 'activity_type' => 'nullable|in:assignment,multiplication,spelling', 'fact_min' => 'nullable|integer|min:1|max:12', 'fact_max' => 'nullable|integer|min:1|max:12', 'book_id' => 'nullable|integer|exists:books,id', 'date' => 'required|date_format:Y-m-d', 'subject' => 'required|string|max:60', 'title' => 'required|string|max:160', 'instructions' => 'required|string|max:8000', 'reference' => 'nullable|string|max:160', 'minutes' => 'nullable|integer|min:1|max:480', 'requires_photo' => 'required|boolean', 'position' => 'required|integer|min:0|max:999']);
    }

    public function save(Request $r, ?Assignment $assignment = null)
    {
        $this->parent($r);
        $data = $this->assignmentData($r);
        if ($assignment) {
            ParentActions::update($r->user(), $assignment->id, $data);
        } else {
            ParentActions::create($r->user(), $data);
        }

        return redirect('/?date='.$data['date'])->with('success', 'Assignment saved.');
    }

    public function copy(Request $r)
    {
        $this->parent($r);
        $data = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|different:from']);
        ParentActions::copy($r->user(), $data['from'], $data['to']);

        return redirect('/?date='.$data['to'])->with('success', 'Assignments copied as new work.');
    }

    public function recurrence(Request $r)
    {
        $this->parent($r);
        $data = $this->assignmentData($r);
        $rule = $r->validate(['weekdays' => 'required|array|min:1|max:7', 'weekdays.*' => 'required|integer|between:1,7|distinct', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:date']);
        ParentActions::recurrence($r->user(), $data + $rule);

        return back()->with('success', 'Recurring assignment created. Work is generated when a date is opened, or by the daily command.');
    }

    public function stopRecurrence(Request $r, Recurrence $recurrence)
    {
        $this->parent($r);
        ParentActions::stopRecurrence($r->user(), $recurrence->id);

        return back()->with('success', 'Recurrence stopped. Existing assignments are preserved.');
    }

    public function reorder(Request $r)
    {
        $this->parent($r);
        $data = $r->validate(['ids' => 'required|array|min:1', 'ids.*' => 'required|integer|distinct|exists:assignments,id']);
        $rows = Assignment::whereIn('id', $data['ids'])->get();
        abort_unless($rows->pluck('date')->map->toDateString()->unique()->count() === 1 && $rows->pluck('student_id')->unique()->count() === 1, 422);
        ParentActions::reorder($r->user(), $rows->first()->date->toDateString(), $data['ids']);

        return response()->json(['saved' => true]);
    }

    public function message(Request $r)
    {
        $data = $r->validate(['body' => 'required|string|max:4000', 'assignment_id' => 'nullable|integer|exists:assignments,id', 'help' => 'nullable|boolean']);
        $student = $r->user()->role === 'student' ? $r->user() : User::where('role', 'student')->firstOrFail();
        if (! empty($data['assignment_id'])) {
            $a = Assignment::findOrFail($data['assignment_id']);
            $this->access($r, $a);
            abort_unless($a->student_id === $student->id, 403);
        }
        if ($r->user()->role === 'parent') {
            ParentActions::message($r->user(), $data['body'], isset($data['assignment_id']) ? (int) $data['assignment_id'] : null);
        } else {
            Message::create($data + ['sender_id' => $r->user()->id, 'student_id' => $student->id]);
            School::record($r->user(), ! empty($data['help']) ? 'help' : 'message', ! empty($data['help']) ? 'Asked Dad for help' : 'Sent a message', $a ?? null);
        }

        return back()->with('success', 'Message sent.');
    }

    public function read(Request $r)
    {
        $data = $r->validate(['through' => 'required|integer|min:1']);
        $student = $r->user()->role === 'student' ? $r->user() : User::where('role', 'student')->firstOrFail();
        Message::where('student_id', $student->id)->where('sender_id', '!=', $r->user()->id)->where('id', '<=', $data['through'])->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['saved' => true]);
    }

    public function heartbeat(Request $r)
    {
        if ($r->user()->role === 'student') {
            $r->user()->update(['last_seen_at' => now()]);
        }
        $student = $r->user()->role === 'student' ? $r->user() : User::where('role', 'student')->firstOrFail();

        return response()->json(['last_seen' => $student->last_seen_at?->toIso8601String(), 'on_break' => $student->on_break, 'unread' => Message::where('student_id', $student->id)->where('sender_id', '!=', $r->user()->id)->whereNull('read_at')->count(), 'submitted' => Assignment::where('student_id', $student->id)->where('status', 'submitted')->count(), 'read_through' => Message::where('student_id', $student->id)->where('sender_id', $r->user()->id)->whereNotNull('read_at')->max('id') ?? 0, 'routine' => DailyRoutine::state($student), 'latest' => Activity::max('id') ?? 0]);
    }

    public function pause(Request $r)
    {
        abort_unless($r->user()->role === 'student', 403);
        $data = $r->validate(['on_break' => 'required|boolean', 'version' => 'required|integer|min:0']);
        DailyRoutine::transition($r->user(), $r->boolean('on_break') ? 'break' : 'resume', (int) $data['version']);

        return back();
    }
}
