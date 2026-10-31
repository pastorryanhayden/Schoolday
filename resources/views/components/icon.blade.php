@props(['name','size'=>20])
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($name)
@case('home') <path d="m3 10 9-7 9 7v10H3Z"/><path d="M9 20v-7h6v7"/> @break
@case('book') <path d="M12 5v16M3 3c4 0 6 0 9 2 3-2 5-2 9-2v15c-4 0-6 0-9 2-3-2-5-2-9-2Z"/> @break
@case('message') <path d="M21 11a8 8 0 0 1-8 8H8l-5 3V11a9 9 0 0 1 18 0Z"/><path d="M8 10h8M8 14h5"/> @break
@case('check') <path d="m5 12 4 4L19 6"/> @break
@case('clock') <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/> @break
@case('arrow') <path d="M4 12h16m-6-6 6 6-6 6"/> @break
@case('plus') <path d="M12 5v14M5 12h14"/> @break
@case('camera') <path d="M3 7h4l2-3h6l2 3h4v14H3Z"/><circle cx="12" cy="13" r="4"/> @break
@case('pause') <path d="M8 5v14M16 5v14"/> @break
@case('activity') <path d="M2 12h5l3-8 4 16 3-8h5"/> @break
@case('calendar') <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 11h18"/> @break
@case('logout') <path d="M9 4H3v16h6M8 12h13m-5-5 5 5-5 5"/> @break
@case('help') <circle cx="12" cy="12" r="9"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 2-3 4M12 17h.01"/> @break
@default <circle cx="12" cy="12" r="8"/>
@endswitch
</svg>
