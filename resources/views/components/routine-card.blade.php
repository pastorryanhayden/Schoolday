@props(['routine', 'parent' => false])
@php
    $open = $routine['open_day'];
    $current = $routine['current'];
    $day = $routine['day'];
    $time = fn($value) => \Carbon\Carbon::parse($value)->timezone(config('app.timezone'))->format('M j, g:i a');
@endphp
<section class="panel routine-card mb-6">
    <div class="flex-between gap-4 flex-wrap">
        <div>
            <div class="eyebrow">Right now</div>
            <h2 x-text="routineLabel">{{ $open ? ($current['label'] ?? 'Choosing the next activity') : 'Schoolday is not running' }}</h2>
            @if($open)
                <p class="muted small">Day started {{ $time($open['started_at']) }}.@if($current) This period started {{ $time($current['started_at']) }}.@endif</p>

            @elseif($day)
                <p class="muted small">Started {{ $time($day['started_at']) }} · Finished {{ $time($day['finished_at']) }}. Unfinished work stays on the plan.</p>
            @else
                <p class="muted small">{{ $parent ? 'Waiting for Noah to start his day.' : 'Start your day, then choose an assignment. You can change the order.' }}</p>
            @endif
        </div>

    </div>
    <details class="time-explanation"><summary>About recorded time</summary><p class="footnote">Reported time only. Breaks and choosing time are separate from work. Ordinary work periods stay open until stopped or corrected. Games count recorded practice.</p></details>
</section>
