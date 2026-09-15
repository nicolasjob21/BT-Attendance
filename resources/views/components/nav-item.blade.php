@props(['active' => false, 'href' => '#', 'icon' => 'grid', 'badge' => null])

@php
$icons = [
    'grid'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zM14 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/>',
    'clock'      => '<circle cx="12" cy="12" r="8"/><path stroke-linecap="round" d="M12 8v4l2.5 2"/>',
    'list'       => '<path stroke-linecap="round" d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
    'calendar'   => '<rect x="4" y="5" width="16" height="16" rx="2"/><path stroke-linecap="round" d="M4 9h16M8 3v4M16 3v4"/>',
    'plus-clock' => '<circle cx="12" cy="12" r="8"/><path stroke-linecap="round" d="M12 9v6M9 12h6"/>',
    'users'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-4-4h-1m-4 5H2v-1a4 4 0 014-4h4a4 4 0 014 4v1zm-3-11a3 3 0 11-6 0 3 3 0 016 0zm7 1a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/>',
    'cash'       => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
    'map-pin'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-6-5.2-6-10a6 6 0 1112 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/>',
    'shield-check' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/>',
];
$base = 'relative flex items-center gap-3 rounded-none border-l-2 px-3 py-2 text-sm font-medium transition-colors';
$state = $active
    ? 'border-brand-500 bg-brand-50 text-brand-700 dark:border-accent-400 dark:bg-brand-500/12 dark:text-white'
    : 'border-transparent text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-brand-500/8 dark:hover:text-white';
@endphp

<a href="{{ $href }}" @click="sidebar = false" :title="collapsed ? '{{ trim($slot) }}' : ''" class="{{ $base }} {{ $state }}">
    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        {!! $icons[$icon] ?? $icons['grid'] !!}
    </svg>
    <span :class="collapsed ? 'lg:hidden' : ''">{{ $slot }}</span>
    @if($badge)
        <span class="ml-auto grid h-5 min-w-[1.25rem] place-items-center rounded-full bg-accent-500 px-1.5 text-[10px] font-bold leading-none text-white animate-pulse" :class="collapsed ? 'lg:absolute lg:right-1 lg:top-1 lg:ml-0' : ''">{{ $badge }}</span>
    @endif
</a>
