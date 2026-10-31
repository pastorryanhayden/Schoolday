@extends('layout')
@section('title', 'Settings · Books')
@section('content')
<a href="{{ route('home') }}" class="back-link">← Back to the schoolday</a>
<div class="page-heading">
    <div>
        <div class="eyebrow">PARENT SETTINGS</div>
        <h1>The books behind the schoolday.</h1>
        <p class="muted">Set up Noah’s books once. Choose a book when you plan an assignment.</p>
    </div>
    <span class="status status-approved">{{ $books->where('active', true)->count() }} active books</span>
</div>
<div class="grid gap-6 xl:grid-cols-2">
    <section class="stack">
        <div class="section-heading"><h2>Your book shelf</h2><span class="small muted">{{ $books->count() }} total</span></div>
        @forelse($books as $book)
            <details class="panel" @if(old('book_id') == $book->id) open @endif>
                <summary class="flex-between">
                    <span><small class="eyebrow">{{ $book->subject }}</small><h2>{{ $book->title }}</h2><span class="small muted">{{ collect([$book->publisher, $book->level])->filter()->join(' · ') }}</span></span>
                    <span class="status {{ $book->active ? 'status-approved' : 'status-not_started' }}">{{ $book->active ? 'Active' : 'Archived' }}</span>
                </summary>
                <form action="{{ route('books.update', $book) }}" method="post" class="stack mt-6">
                    @csrf
                    <input type="hidden" name="book_id" value="{{ $book->id }}">
                    <x-book-editor :book="$book"/>
                    <p class="muted small">Uncheck Active book to archive it. Existing assignments and recurring plans keep their book links.</p>
                    <button class="btn primary">Save book <x-icon name="check" size="17"/></button>
                </form>
            </details>
        @empty
            <div class="panel empty"><x-icon name="book" size="35"/><h2>A shelf ready to fill.</h2><p>Add the books Noah uses for math, writing, literature, handwriting, and spelling.</p></div>
        @endforelse
        <p class="footnote">Book defaults are copied when you select a book for a new assignment. Editing a book does not rewrite instructions on existing assignments. Lesson references advance only when you edit them; there are no curriculum integrations.</p>
    </section>
    <section class="panel self-start">
        <div class="section-heading"><h2>Add a book or course</h2><x-icon name="plus"/></div>
        <p class="muted">Use the exact level and edition on your copy. You can leave fields blank until you have the book in front of you.</p>
        <form action="{{ route('books.store') }}" method="post" class="stack mt-5">
            @csrf
            <x-book-editor/>
            <button class="btn primary">Add book <x-icon name="plus" size="17"/></button>
        </form>
    </section>
</div>
@endsection
