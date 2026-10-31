@props(['routine', 'parent' => false, 'assignments' => []])
@php
    $day = $routine['day'];
    $local = fn($value) => \Carbon\Carbon::parse($value)->timezone(config('app.timezone'));
    $time = fn($value) => $local($value)->format('M j · g:i:s a');
    $inputTime = fn($value) => $local($value)->format('Y-m-d\TH:i:s');
@endphp
<section class="panel mb-6">
    <div class="section-heading"><h2>The schoolday timeline</h2><span class="small muted">{{ $routine['date'] }} · {{ config('app.timezone') }}</span></div>
    <p class="muted">Reported sessions, across both devices. Open periods count until explicitly stopped; refresh to update totals.</p>
    <div class="grid gap-3 sm:grid-cols-3 my-5">
        @foreach(['work' => 'Reported work', 'break' => 'Breaks', 'idle' => 'Choosing / between activities'] as $kind => $label)
            <div class="rounded-xl bg-stone-50 p-4"><strong>{{ round($routine['totals_seconds'][$kind] / 60, 1) }} min</strong><div class="small muted">{{ $label }}</div></div>
        @endforeach
    </div>
    @if($day)
        <p class="small"><strong>Day started</strong> {{ $time($day['started_at']) }} @if($day['finished_at']) · <strong>Day finished</strong> {{ $time($day['finished_at']) }} @else · Still open @endif</p>
        <div class="stack mt-5">
            @foreach($day['sessions'] as $period)
                <article class="rounded-xl border border-stone-200 p-4">
                    <div class="flex-between gap-3 flex-wrap"><strong>{{ $period['label'] }}</strong><span class="status {{ $period['ended_at'] ? 'status-approved' : 'status-in_progress' }}">{{ $period['ended_at'] ? 'Recorded' : 'Open period' }}</span></div>
                    <p class="small muted">{{ $time($period['started_at']) }} → {{ $period['ended_at'] ? $time($period['ended_at']) : 'until stopped' }}</p>
                    @if($period['assignment_id'])<a class="text-btn" href="{{ route('assignment', $period['assignment_id']) }}">Open assignment →</a>@endif
                    @if($parent)
                        <details class="mt-3"><summary class="text-btn">Correct this period</summary>
                            <form action="{{ route('timeline.correct', [$day['id'], $period['id']]) }}" method="post" class="stack mt-3">
                                @csrf<input type="hidden" name="version" value="{{ $routine['version'] }}">
                                <div class="form-grid"><label>Started at<input type="datetime-local" step="1" name="started_at" value="{{ $inputTime($period['started_at']) }}" required></label><label>Ended at<input type="datetime-local" step="1" name="ended_at" value="{{ $period['ended_at'] ? $inputTime($period['ended_at']) : '' }}" required></label>
                                <label>Period type<select name="kind">@foreach(['work' => 'Work', 'break' => 'Break', 'idle' => 'Choosing / between activities'] as $kind => $label)<option value="{{ $kind }}" @selected($period['kind'] === $kind)>{{ $label }}</option>@endforeach</select></label>
                                <label>Assignment (for work)<select name="assignment_id"><option value="">None</option>@foreach($assignments as $assignment)<option value="{{ $assignment->id }}" @selected($period['assignment_id'] === $assignment->id)>{{ $assignment->title }}</option>@endforeach</select></label></div>
                                <label>Reason for correction<textarea name="reason" rows="2" maxlength="1000" required></textarea></label>
                                <button class="btn secondary" :disabled="routineStale">Save correction</button>
                            </form>
                        </details>
                    @endif
                </article>
            @endforeach
        </div>
        @if(count($routine['by_assignment']))
            <h3 class="mt-6">Reported work by activity</h3>
            @foreach($routine['by_assignment'] as $total)<p class="small mt-2">{{ $total['label'] }} · <strong>{{ round($total['seconds'] / 60, 1) }} min</strong></p>@endforeach
        @endif
        @if($parent)
            <details class="mt-6"><summary class="text-btn">Add a missed period</summary>
                <p class="small muted mt-3">Shorten any overlapping period first, then fill the gap. These times are reported corrections, not verified work.</p>
                <form action="{{ route('timeline.add', $day['id']) }}" method="post" class="stack mt-3">
                    @csrf<input type="hidden" name="version" value="{{ $routine['version'] }}">
                    <div class="form-grid"><label>Started at<input type="datetime-local" step="1" name="started_at" required></label><label>Ended at<input type="datetime-local" step="1" name="ended_at" required></label><label>Period type<select name="kind"><option value="work">Work</option><option value="break">Break</option><option value="idle">Choosing / between activities</option></select></label><label>Assignment (for work)<select name="assignment_id"><option value="">None</option>@foreach($assignments as $assignment)<option value="{{ $assignment->id }}">{{ $assignment->title }}</option>@endforeach</select></label></div>
                    <label>Reason<textarea name="reason" rows="2" maxlength="1000" required></textarea></label><button class="btn secondary" :disabled="routineStale">Add reported period</button>
                </form>
            </details>
            <details class="mt-5"><summary class="text-btn">Correct day start / finish</summary>
                <p class="small muted mt-3">Adjust conflicting periods first. Setting a finish closes the open period at that time. Leave finish blank to keep an open day running.</p>
                <form action="{{ route('timeline.day', $day['id']) }}" method="post" class="stack mt-3">
                    @csrf<input type="hidden" name="version" value="{{ $routine['version'] }}">
                    <div class="form-grid"><label>Day started<input name="started_at" type="datetime-local" step="1" value="{{ $inputTime($day['started_at']) }}" required></label><label>Day finished<input name="finished_at" type="datetime-local" step="1" value="{{ $day['finished_at'] ? $inputTime($day['finished_at']) : '' }}"></label></div>
                    <label>Reason<textarea name="reason" rows="2" required maxlength="1000"></textarea></label><button class="btn secondary" :disabled="routineStale">Save day correction</button>
                </form>
            </details>
            @if(count($day['corrections']))
                <details class="mt-6"><summary class="text-btn">Correction history · {{ count($day['corrections']) }}</summary>
                    @foreach($day['corrections'] as $correction)
                        <article class="border-t border-stone-200 mt-4 pt-4 small"><strong>{{ $correction['reason'] }}</strong><p class="muted">Parent correction · {{ $time($correction['created_at']) }}</p>
                            @foreach(['before' => 'Originally', 'after' => 'Corrected to'] as $key => $label)
                                @php($record = $correction[$key])
                                <p>{{ $label }}: @if(empty($record)) No period recorded. @else {{ $record['label'] ?? 'Schoolday' }} · {{ $time($record['started_at']) }} → {{ !empty($record['ended_at'] ?? $record['finished_at'] ?? null) ? $time($record['ended_at'] ?? $record['finished_at']) : 'open' }} @endif</p>
                            @endforeach
                        </article>
                    @endforeach
                </details>
            @endif
        @endif
    @else
        <p class="empty-inline">No schoolday was started on this date. Older work has no session times; we haven’t invented them from activity or contact logs.</p>
    @endif
</section>
