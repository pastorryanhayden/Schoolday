<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParentRecurrenceResource;
use App\Models\Recurrence;
use App\Services\ParentActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ParentRecurrenceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ParentRecurrenceResource::collection(Recurrence::where('student_id', ParentActions::student()->id)->orderByDesc('active')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        return (new ParentRecurrenceResource(ParentActions::recurrence($request->user(), $request->all())))->response()->setStatusCode(201);
    }

    public function stop(Request $request, Recurrence $recurrence): ParentRecurrenceResource
    {
        return new ParentRecurrenceResource(ParentActions::stopRecurrence($request->user(), $recurrence->id));
    }
}
