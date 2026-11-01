@extends('layout')
@section('title', 'Multiplication practice')
@section('content')
@php($parent = auth()->user()->role === 'parent')
<a href="/?date={{ $a->date->toDateString() }}" class="back-link">← Back to the schoolday</a>
<div class="page-heading"><div><div class="eyebrow">Math · {{ $a->date->format('M j') }}</div><h1>{{ $a->title }}</h1></div>@if($parent)<x-status :status="$a->status"/>@endif</div>
<x-routine-card :routine="$routine" :parent="$parent"/>
@if($a->feedback)<section class="feedback panel mb-6"><h2>Feedback from Dad</h2><p>{{ $a->feedback }}</p></section>@endif
@if(!$parent)
<section class="panel practice-game" @keydown.enter.window="if(mode==='feedback' &amp;&amp; !busy &amp;&amp; !routineStale){$event.preventDefault();next()}" x-data="practiceGame(@js(['url' => route('practice.act', $a), 'version' => $routine['version'], 'summary' => $summary, 'status' => $a->status, 'complete' => in_array($a->status, ['submitted', 'approved'])]))">
    <div class="flex-between gap-3 flex-wrap"><span class="eyebrow">{{ $a->fact_min }}–{{ $a->fact_max }} TIMES TABLES</span><span class="small muted" x-text="summary.round.correct+' / '+summary.round.attempts+' correct'"></span></div>
    <p class="muted mt-3">{{ $a->instructions }}</p>
    <div class="progress my-5" role="progressbar" aria-label="Practice progress" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.min(100,Math.round(100*(summary.question_target ? summary.round.attempts/summary.question_target : summary.round.practice_ms/summary.target_ms)))"><span :style="'width:'+Math.min(100,100*(summary.question_target ? summary.round.attempts/summary.question_target : summary.round.practice_ms/summary.target_ms))+'%'"></span></div>
    <div x-show="mode==='ready' || mode==='paused'" class="stack text-center py-6">
        <h2 x-text="mode==='ready'?'Ready to practice?':'Practice is paused.'"></h2>
        <p class="muted">Aim for accurate answers. There’s no countdown. Slower facts will come back for more practice.</p>
        <p class="small muted">@if($a->question_target){{ $a->question_target }} questions. Take your time; each saved answer counts toward the target.@else{{ $a->minutes }} {{ Str::plural('minute', $a->minutes) }} of recorded practice. Breaks and time away don’t fill the target.@endif</p>
        @if($routine['open_day'])<button class="btn primary mx-auto" :disabled="busy || routineStale" @click="start()" x-text="mode==='ready'?'Start practice':'Resume practice'"></button>@else<a class="btn primary mx-auto" href="{{ route('home') }}">Start my day first →</a>@endif
    </div>
    <div x-show="mode==='question' || mode==='unsaved'" x-cloak class="practice-question stack">
        <div class="flex justify-center"><button type="button" class="chart-info" aria-label="Open 12 by 12 multiplication chart" title="Multiplication chart" :disabled="busy || mode!=='question' || routineStale" @click="help()">i</button></div>
        <div class="practice-equation" aria-live="polite"><span x-text="question?.left"></span><span aria-label="times">×</span><span x-text="question?.right"></span><span>=</span><span>?</span></div>
        <form @submit.prevent="answerQuestion()" class="stack">
            <label class="sr-only" for="fact-answer">Your answer</label><input id="fact-answer" class="practice-answer" type="text" inputmode="none" autocomplete="off" maxlength="3" pattern="[0-9]*" x-model="answer" x-ref="answer" :disabled="busy || mode==='unsaved' || routineStale" @input="answer=answer.replace(/[^0-9]/g,'').slice(0,3)">
            <div class="practice-keypad"><template x-for="digit in [1,2,3,4,5,6,7,8,9]"><button type="button" class="btn secondary" :disabled="busy || mode==='unsaved' || routineStale" @click="append(digit)" x-text="digit"></button></template><button type="button" class="btn secondary" :disabled="busy || mode==='unsaved' || routineStale" @click="answer=''">Clear</button><button type="button" class="btn secondary" :disabled="busy || mode==='unsaved' || routineStale" @click="append(0)">0</button><button type="button" class="btn secondary" :disabled="busy || mode==='unsaved' || routineStale" @click="answer=answer.slice(0,-1)" aria-label="Delete last digit">⌫</button></div>
            <button class="btn primary" :disabled="busy || answer==='' || mode==='unsaved' || routineStale">Check answer →</button>
        </form>
        <button class="btn secondary" x-show="mode==='unsaved'" :disabled="busy" @click="retry()">Retry saving this answer</button>
    </div>
    <div x-show="mode==='feedback'" x-cloak class="stack text-center py-6" role="status">
        <h2 x-text="feedback?.correct?'That’s right!':'Let’s keep practicing this one.'"></h2>
        <p class="practice-equation" x-text="feedback ? feedback.left+' × '+feedback.right+' = '+feedback.answer : ''"></p>
        <p class="small muted" x-show="feedback?.assisted">Chart used · we’ll practice this fact again.</p>
        <p class="muted" x-text="feedback?.correct?'Correct. Continue when ready.':'You’ll see this fact again in a later round.'"></p>
        <button class="btn primary mx-auto" :disabled="busy || routineStale" @click="next()">Next fact →</button>
    </div>
    <div x-show="mode==='complete'" class="stack text-center py-6" role="status">
        <h2>Practice complete. Nice work!</h2><p class="muted" x-text="approved?'Dad approved this practice.':'Your results are saved for Dad’s review.'">Your results are saved for Dad’s review.</p><p><strong x-text="summary.round.correct+' of '+summary.round.attempts+' correct'"></strong></p><a class="btn primary mx-auto" href="{{ route('home') }}">Choose my next activity →</a>
    </div>
    <p class="error-text mt-4" x-show="error" x-text="error" role="alert"></p>
    <button class="text-btn mt-5" x-show="mode==='question' || mode==='feedback' || mode==='unsaved'" :disabled="busy || routineStale" @click="pause()">Pause practice</button>
    <p class="footnote">Use the keypad or type on your laptop. Enter checks an answer and moves to the next fact. Leaving this page or a long gap pauses the question. Saved answers and brief feedback time count toward practice; idle time does not.</p>
    <div class="chart-modal" x-show="chartOpen" x-cloak @keydown.escape.window="if(chartOpen) closeChart()" @keydown.tab.prevent="$event.target===$refs.chartClose ? $refs.chartInfo.focus() : $refs.chartClose.focus()" role="dialog" aria-modal="true" aria-label="12 by 12 multiplication chart">
        <div class="chart-panel" @click.outside="closeChart()"><div class="flex-between gap-3"><h2>Multiplication chart</h2><button type="button" class="btn secondary" x-ref="chartClose" @click="closeChart()">Back to question ×</button></div><p class="muted small mt-3" tabindex="0" x-ref="chartInfo">Find the first number down the left and the second across the top. Using the chart tells Dad which fact needs more practice.</p>
        <div class="overflow-x-auto mt-4"><table class="multiplication-chart"><caption class="sr-only">Multiplication table from 1 to 12. Each cell is its row times its column.</caption><thead><tr><th scope="col">×</th>@for($column=1;$column<=12;$column++)<th scope="col">{{ $column }}</th>@endfor</tr></thead><tbody>@for($row=1;$row<=12;$row++)<tr><th scope="row">{{ $row }}</th>@for($column=1;$column<=12;$column++)<td>{{ $row*$column }}</td>@endfor</tr>@endfor</tbody></table></div></div>
    </div>
    <noscript><p class="error-text">This game needs JavaScript enabled.</p></noscript>
</section>
@else
<div class="grid gap-6 xl:grid-cols-2 mb-6"><x-practice-summary :summary="$summary"/><section class="panel stack"><h2>Review the practice</h2><p class="muted">{{ $a->fact_min }}–{{ $a->fact_max }} times tables · {{ $a->question_target ? $a->question_target.' question target' : $a->minutes.' minute target' }}. Review accuracy and recall alongside the fact history below.</p>
@if($a->status === 'submitted')<form action="/assignments/{{ $a->id }}/review" method="post" class="stack" x-data="{decision:'approved'}">@csrf<label>Decision<select name="decision" x-model="decision"><option value="approved">Approve this practice</option><option value="needs_changes">Request another practice round</option></select></label><label>Feedback<textarea name="feedback" rows="3" maxlength="4000" :required="decision==='needs_changes'"></textarea></label><button class="btn primary">Send review</button></form>@else<p class="muted">{{ $a->status === 'approved' ? 'This practice is approved.' : 'Noah will submit this automatically when he reaches the practice target.' }}</p>@endif
</section></div>
@if($a->status === 'not_started' && !$a->started_at)<details class="panel mb-6"><summary>Edit practice</summary><form method="post" action="/parent/assignments/{{ $a->id }}" class="stack">@csrf<x-editor :date="$a->date->toDateString()" :a="$a"/><button class="btn primary">Save changes</button></form></details>@endif
<section class="panel stack"><div><h2>Multiplication fact history</h2><p class="muted">Across all practice assignments. Reversed pairs share a fact. Recent speed is the median of the last ten correct answers without the chart. Chart use is flagged even if a question is left unanswered.</p><p class="small muted">“Quick recently” means the latest answer was correct and recent correct recall is within 3 seconds. It’s a practice target, not a grade or a mastery claim.</p></div>
@forelse($facts['facts'] as $fact)<article class="flex-between gap-4 flex-wrap border-t border-stone-200 pt-4"><div><strong>{{ $fact['fact'] }}</strong><p class="small muted">{{ $fact['correct'] }}/{{ $fact['attempts'] }} correct · {{ $fact['accuracy'] }}% accuracy</p></div><div><strong>{{ $fact['recent_correct_median_ms'] === null ? 'No independent recall yet' : round($fact['recent_correct_median_ms']/1000,1).' sec' }}</strong><p class="small muted">{{ $fact['needs_drill'] ? 'More practice' : ($fact['quick_recently'] ? 'Quick recently' : 'Keep practicing') }} · Chart used {{ $fact['help_uses'] }} {{ Str::plural('time', $fact['help_uses']) }}</p></div></article>@empty<p class="muted">Fact results will appear after Noah answers questions.</p>@endforelse
</section>
@endif
@endsection
