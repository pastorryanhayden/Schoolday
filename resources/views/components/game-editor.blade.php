@props(['game' => null])
@php($config=$game?->configuration ?? [])
@php($value=fn($key,$default='')=>old($key,$config[$key]??$default))
<div class="form-grid" x-data="{kind:@js($value('activity_type','multiplication'))}">
<label class="wide">Game<select name="activity_type" x-model="kind"><option value="multiplication">Times tables</option><option value="spelling">Spelling · all three modes</option></select></label>
<label class="wide">Name<input name="title" value="{{ $value('title') }}" required maxlength="160" placeholder="e.g. Times tables · 6, 7 and 8"></label>
<label class="wide">Instructions<textarea name="instructions" rows="2" required maxlength="8000">{{ $value('instructions','Practice these skills, one question at a time.') }}</textarea></label>
<div class="wide form-grid" x-show="kind==='multiplication'"><label>Smallest factor<input name="fact_min" type="number" min="1" max="12" value="{{ $value('fact_min',1) }}" required></label><label>Largest factor<input name="fact_max" type="number" min="1" max="12" value="{{ $value('fact_max',12) }}" required></label></div>
<label class="wide" x-show="kind==='spelling'">Spelling list<textarea name="spelling_words" rows="6" :disabled="kind!=='spelling'" :required="kind==='spelling'" maxlength="3000" placeholder="One word per line">{{ is_array($value('spelling_words')) ? implode("\n", $value('spelling_words')) : $value('spelling_words') }}</textarea></label>
<label>Minutes per round<input name="minutes" type="number" min="1" max="15" value="{{ $value('minutes',5) }}" required></label>
<label>Questions instead (optional)<input name="question_target" type="number" min="1" max="100" value="{{ $value('question_target') }}"></label>
<p class="small muted wide">A question target replaces the time target. Every play starts a separate round; earlier results keep their original list and settings.</p>
<label class="check-label wide"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(old('active',$game?->active ?? true))> Available in Noah’s Games drawer</label>
</div>
