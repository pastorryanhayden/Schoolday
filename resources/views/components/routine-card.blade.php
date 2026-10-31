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
            <div class="eyebrow">{{ $parent ? 'NOAH’S CURRENT ACTIVITY · SHARED ACROSS DEVICES' : 'YOUR SCHOOL DAY · LAPTOP + TABLET' }}</div>
            <h2 x-text="routineLabel">{{ $open ? ($current['label'] ?? 'Choosing the next activity') : 'Schoolday is not running' }}</h2>
            @if($open)
                <p class="muted small">Day started {{ $time($open['started_at']) }}.@if($current) This period started {{ $time($current['started_at']) }}.@endif</p>
                @if(substr($open['date'], 0, 10) !== today()->toDateString())<p class="error-text">This day was left open from {{ $time($open['started_at']) }}. Finish it, or ask Dad to correct the stop time.</p>@endif
            @elseif($day)
                <p class="muted small">Started {{ $time($day['started_at']) }} · Finished {{ $time($day['finished_at']) }}. Unfinished work stays on the plan.</p>
            @else
                <p class="muted small">{{ $parent ? 'Waiting for Noah to start his day.' : 'Start your day, then choose an assignment. You can change the order.' }}</p>
            @endif
        </div>
        @unless($parent)
            <div class="flex gap-3 flex-wrap">
                @if(!$open)
                    <x-routine-action action="day-start" :version="$routine['version']" :label="$day ? 'Reopen my day' : 'Start my day'" primary/>
                @else
                    @if($current && $current['kind'] === 'break')
                        <x-routine-action action="resume" :version="$routine['version']" label="Resume work" primary/>
                    @else
                        <x-routine-action action="break" :version="$routine['version']" label="Take a break"/>
                        @if($current && $current['kind'] === 'work')<x-routine-action action="activity-stop" :version="$routine['version']" label="Stop this activity"/>@endif
                    @endif
                    <a class="btn secondary" href="{{ route('routine.finish') }}">Finish my day →</a>
                @endif
            </div>
        @endunless
    </div>
    <p class="footnote">Reported time only. Breaks and choosing time are separate from work. If you forget to stop, the period stays open until you or Dad correct it.</p>
</section>
