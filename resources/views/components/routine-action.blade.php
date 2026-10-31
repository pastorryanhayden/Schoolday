@props(['action', 'version', 'label', 'primary' => false])
<form action="{{ route('routine.transition') }}" method="post">
    @csrf
    <input type="hidden" name="action" value="{{ $action }}">
    <input type="hidden" name="version" value="{{ $version }}">
    <button {{ $attributes->class(['btn', 'primary' => $primary, 'secondary' => !$primary]) }} :disabled="routineStale">{{ $label }}</button>
</form>
