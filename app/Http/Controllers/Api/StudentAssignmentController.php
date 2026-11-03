<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentAssignmentResource;
use App\Http\Resources\StudentPhotoResource;
use App\Models\Assignment;
use App\Models\Photo;
use App\Models\User;
use App\Services\DailyRoutine;
use App\Services\School;
use App\Services\StudentActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudentAssignmentController extends Controller
{
    protected function student(Request $request): User
    {
        return $request->user();
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d', 'extra' => 'nullable|boolean', 'page' => 'nullable|integer|min:1', 'limit' => 'nullable|integer|min:1|max:100']);
        $date = $data['date'] ?? today()->toDateString();
        School::generate($date);
        $assignments = Assignment::where('student_id', $this->student($request)->id)->whereDate('date', $date)->where('is_extra_practice', $request->boolean('extra'))->with(['photos', 'book']);
        if ($request->boolean('extra')) {
            $assignments->orderByDesc('id');
        } else {
            $assignments->orderBy('position')->orderBy('id');
        }
        $page = $assignments->simplePaginate($data['limit'] ?? 100);

        return response()->json(['data' => StudentAssignmentResource::collection($page->getCollection()), 'meta' => ['has_more' => $page->hasMorePages(), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null]]);
    }

    protected function own(Request $request, Assignment $assignment): void
    {
        abort_unless($assignment->student_id === $this->student($request)->id, 404);
    }

    public function show(Request $request, Assignment $assignment): StudentAssignmentResource
    {
        $this->own($request, $assignment);

        return new StudentAssignmentResource($assignment->load(['photos', 'book']));
    }

    public function start(Request $request, Assignment $assignment): JsonResponse
    {
        $this->own($request, $assignment);
        $data = $request->validate(['version' => 'required|integer|min:0']);
        DailyRoutine::transition($request->user(), 'activity-start', (int) $data['version'], $assignment->id);

        return $this->workResponse($request, $assignment);
    }

    public function draft(Request $request, Assignment $assignment): JsonResponse
    {
        $this->own($request, $assignment);
        $request->validate(['note' => 'present|nullable|string|max:4000']);
        StudentActions::draft($request->user(), $assignment, $request->only('note'));

        return $this->workResponse($request, $assignment);
    }

    public function submit(Request $request, Assignment $assignment): JsonResponse
    {
        $this->own($request, $assignment);
        StudentActions::submit($request->user(), $assignment, $request->only('note'));

        return $this->workResponse($request, $assignment);
    }

    private function workResponse(Request $request, Assignment $assignment): JsonResponse
    {
        return response()->json(['data' => ['assignment' => new StudentAssignmentResource($assignment->fresh()->load(['photos', 'book'])), 'routine' => DailyRoutine::state($request->user())]]);
    }

    public function upload(Request $request, Assignment $assignment): JsonResponse
    {
        $this->own($request, $assignment);
        $photo = StudentActions::upload($request->user(), $assignment, $request->file('photo'));

        return (new StudentPhotoResource($photo))->response()->setStatusCode(201);
    }

    public function photo(Request $request, Photo $photo): BinaryFileResponse
    {
        $this->own($request, Assignment::findOrFail($photo->assignment_id));
        abort_unless(Storage::disk('local')->exists($photo->path), 404, 'This photo is unavailable.');

        return response()->file(Storage::disk('local')->path($photo->path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function removePhoto(Request $request, Photo $photo): JsonResponse
    {
        $this->own($request, Assignment::findOrFail($photo->assignment_id));
        StudentActions::removePhoto($request->user(), $photo);

        return response()->json(['data' => ['removed' => true]]);
    }
}
