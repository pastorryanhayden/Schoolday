@props(['book' => null])
@php
    $editingThisBook = session()->hasOldInput() && (string) old('book_id', '') === (string) ($book?->id ?? '');
    $value = fn($key, $default = '') => $editingThisBook ? old($key, $default) : ($book?->$key ?? $default);
@endphp
<div class="form-grid">
    <label>Subject
        <select name="subject" required>
            @foreach(['Math', 'Writing', 'Literature', 'Handwriting', 'Spelling', 'Other'] as $subject)
                <option @selected($value('subject') === $subject)>{{ $subject }}</option>
            @endforeach
        </select>
    </label>
    <label>Book or course title<input name="title" value="{{ $value('title') }}" maxlength="160" required placeholder="e.g. Math 7"></label>
    <label>Publisher / curriculum<input name="publisher" value="{{ $value('publisher') }}" maxlength="160" placeholder="e.g. Teaching Textbooks"></label>
    <label>Level / edition<input name="level" value="{{ $value('level') }}" maxlength="100" placeholder="e.g. Level 7 · 4th edition"></label>
    <label>Default lesson / page reference<input name="reference" value="{{ $value('reference') }}" maxlength="160" placeholder="e.g. Lesson 43 or pages 28–29"></label>
    <label>Estimated minutes<input name="minutes" type="number" value="{{ $value('minutes') }}" min="1" max="480"></label>
    <label class="wide">Default instructions<textarea name="instructions" rows="3" maxlength="8000" placeholder="Instructions to copy into new assignments…">{{ $value('instructions') }}</textarea></label>
    <label class="check-label"><input type="hidden" name="requires_photo" value="0"><input type="checkbox" name="requires_photo" value="1" @checked($value('requires_photo', false))> Require worksheet photos by default</label>
    <label class="check-label"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($value('active', true))> Active book</label>
</div>
