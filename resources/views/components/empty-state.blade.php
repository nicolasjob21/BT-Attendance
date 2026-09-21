{{-- Empty table body: icon, one line of context, and (optionally) the next action. --}}
@props(['title', 'hint' => null, 'href' => null, 'action' => null, 'icon' => 'inbox'])
@php
    $icons = [
        'inbox' => 'M3 13h4l2 3h6l2-3h4M5 6h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z',
        'clock' => 'M12 8v4l2.5 2M12 21a9 9 0 110-18 9 9 0 010 18z',
        'calendar' => 'M4 9h16M8 3v4M16 3v4M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z',
        'cash' => 'M3 8h18v10H3zM12 13a2 2 0 100-4 2 2 0 000 4zM6 8V6h12v2',
        'users' => 'M16 11a4 4 0 10-8 0 4 4 0 008 0zM4 21a8 8 0 0116 0',
        'map' => 'M12 21s-6-5.5-6-10a6 6 0 1112 0c0 4.5-6 10-6 10zM12 11a1.5 1.5 0 100-3 1.5 1.5 0 000 3z',
        'shield' => 'M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3zM9 12l2 2 4-4',
    ];
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-2 px-4 py-12 text-center']) }}>
    <span class="grid h-12 w-12 place-items-center rounded-full bg-gray-100 text-gray-400 dark:bg-slate-800 dark:text-slate-500">
        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$icon] ?? $icons['inbox'] }}"/></svg>
    </span>
    <p class="text-sm font-medium text-gray-700 dark:text-slate-200">{{ $title }}</p>
    @if($hint)<p class="max-w-md text-xs text-gray-500 dark:text-slate-400">{{ $hint }}</p>@endif
    @if($href && $action)<a href="{{ $href }}" class="btn-app btn-sm btn-outline-brand mt-2">{{ $action }}</a>@endif
</div>
