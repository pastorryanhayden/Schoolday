<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParentBookResource;
use App\Models\Book;
use App\Services\ParentActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ParentBookController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ParentBookResource::collection(Book::where('student_id', ParentActions::student()->id)->orderByDesc('active')->orderBy('subject')->orderBy('title')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        return (new ParentBookResource(ParentActions::saveBook($request->user(), $request->all())))->response()->setStatusCode(201);
    }

    public function update(Request $request, Book $book): ParentBookResource
    {
        return new ParentBookResource(ParentActions::saveBook($request->user(), $request->all(), $book->id));
    }
}
