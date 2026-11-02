<div x-show="mode==='complete'" x-cloak class="practice-finish stack text-center" role="status">
    <div class="celebration"><x-spark/><span class="celebration-dot dot-one"></span><span class="celebration-dot dot-two"></span><span class="celebration-dot dot-three"></span></div>
    <span class="eyebrow">YOU DID IT</span>
    <h2>Practice complete. Nice work!</h2>
    <p class="muted" x-text="extra?'Extra practice saved. Ready for another adventure?':(approved?'Dad approved this practice.':'Your results are saved for Dad’s review.')"></p>
    <div class="round-results">
        <div class="round-result"><span>Answered</span><strong x-text="summary.round.attempts"></strong></div>
        <div class="round-result"><span>Correct</span><strong x-text="summary.round.correct+' / '+summary.round.attempts"></strong></div>
    </div>
    <p class="small muted" x-show="feedback" x-text="feedback ? 'Last answer: '+feedback.answer+(feedback.correct ? ' · correct!' : ' · keep practicing') : ''"></p>
    <button type="button" class="btn primary mx-auto" @click="openGames($el)" x-show="extra">Play another round →</button>
    <a class="btn secondary mx-auto" href="{{ route('home') }}">Back to my schoolday →</a>
</div>
