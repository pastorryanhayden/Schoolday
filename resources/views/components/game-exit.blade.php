<button type="button" class="game-close" x-ref="exitOpener" @click="askToExit()" :disabled="busy" aria-label="Exit game" aria-haspopup="dialog">×</button>
<div class="game-connection" x-show="routineStale || !online" x-cloak role="status">
    <span x-text="routineStale ? 'Activity changed on another device.' : 'Connection interrupted. Saved answers are safe.'"></span>
    <a href="" x-show="routineStale" @click.prevent="location.reload()">Refresh game</a>
</div>
<div class="game-exit-overlay" x-show="exitOpen" x-cloak @keydown.escape.stop.window="if(exitOpen) keepPlaying()" @keydown.tab.prevent="($event.target===$refs.keepPlaying ? $refs.leaveGame : $refs.keepPlaying).focus()">
    <section class="game-exit-dialog stack text-center" role="dialog" aria-modal="true" aria-labelledby="game-exit-title" aria-describedby="game-exit-description">
        <x-spark class="practice-ready-spark"/>
        <h2 id="game-exit-title">Leave this game?</h2>
        <p id="game-exit-description" class="muted">Your saved answers are safe. You can come back and practice more.</p>
        <p class="small muted" x-show="pending">Your latest answer hasn’t saved yet. Stay to retry it, or leave without saving that answer.</p>
        <button type="button" class="btn primary" x-ref="keepPlaying" @click="keepPlaying()" :disabled="busy">Keep playing</button>
        <a href="{{ route('home') }}" class="btn secondary" x-ref="leaveGame" :aria-disabled="busy" @click="if(busy) $event.preventDefault(); else stopSpeech()">Leave game</a>
    </section>
</div>
