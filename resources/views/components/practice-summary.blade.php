@props(['summary'])
<section class="panel stack">
    <div class="section-heading"><h2>Practice results</h2><span class="muted small">Round {{ $summary['revision'] }}</span></div>
    <div class="grid gap-4 sm:grid-cols-3">
        <div><strong>{{ $summary['round']['correct'] }} / {{ $summary['round']['attempts'] }}</strong><p class="small muted">correct answers</p></div>
        <div><strong>{{ $summary['round']['accuracy'] === null ? '—' : $summary['round']['accuracy'].'%' }}</strong><p class="small muted">accuracy</p></div>
        <div><strong>{{ round($summary['round']['practice_ms'] / 60000, 1) }} min</strong><p class="small muted">recorded practice · {{ $summary['target_ms'] / 60000 }} min target</p></div>
    </div>
    <p class="small muted">Median independent correct response: {{ $summary['round']['median_correct_ms'] === null ? 'No independent correct answers yet.' : round($summary['round']['median_correct_ms'] / 1000, 1).' seconds.' }} Paused and unanswered questions do not count. Brief feedback time counts toward practice.</p>
    <p class="small muted">Chart used for {{ $summary['round']['help_uses'] }} {{ Str::plural('question', $summary['round']['help_uses']) }}. Assisted answers count for accuracy, but not independent recall speed.</p>
    @if(count($summary['rounds']) > 1)
        <details><summary class="text-btn">Earlier rounds</summary>@foreach($summary['rounds'] as $round)<p class="small mt-3">Round {{ $round['revision'] }} · {{ $round['correct'] }}/{{ $round['attempts'] }} correct · {{ round($round['practice_ms'] / 60000, 1) }} min</p>@endforeach</details>
    @endif
</section>
