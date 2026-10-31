<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Services\ParentActions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BookController extends Controller
{
    public function index(Request $request): View
    {
        ParentActions::authorize($request->user());
        $books = Book::where('student_id', ParentActions::student()->id)->orderByDesc('active')->orderBy('subject')->orderBy('title')->get();

        return view('settings', compact('books'));
    }

    public function save(Request $request, ?Book $book = null): RedirectResponse
    {
        ParentActions::authorize($request->user());
        $data = $request->only(['subject', 'title', 'publisher', 'level', 'reference', 'instructions', 'minutes', 'requires_photo', 'active']);
        ParentActions::saveBook($request->user(), $data, $book?->id);

        return redirect()->route('settings')->with('success', 'Book saved. Existing assignment instructions stay as written.');
    }
}
