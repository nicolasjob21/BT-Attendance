@props(['active' => false, 'href' => '#', 'icon' => 'grid'])

@php
$icons = [
    'grid'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zM14 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/>',
    'clock'      => '<circle cx="12" cy="12" r="8"/><path stroke-linecap="round" d="M12 8v4l2.5 2"/>',
    'list'       => '<path stroke-linecap="round" d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
    'calendar'   => '<rect x="4" y="5" width="16" height="16" rx="2"/><path stroke-linecap="round" d="M4 9h16M8 3v4M16 3v4"/>',
    'plus-clock' => '<circle cx="12" cy="12" r="8"/><path stroke-linecap="round" d="M12 9v6M9 12h6"/>',
    'users'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-4-4h-1m-4 5H2v-1a4 4 0 014-4h4a4 4 0 014 4v1zm-3-11a3 3 0 11-6 0 3 3 0 016 0zm7 1a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/>',
    'cash'       => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
];
$base = 'relative flex items-center gap-3 rounded-none border-l-2 px-3 py-2 text-sm font-medium transition-colors';
$state = $active
    ? 'border-accent-400 bg-brand-500/12 text-white'
    : 'border-transparent text-slate-400 hover:bg-brand-500/8 hover:text-white';
@endphp

<a href="{{ $href }}" @click="sidebar = false" :title="collapsed ? '{{ trim($slot) }}' : ''" class="{{ $base }} {{ $state }}">
    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        {!! $icons[$icon] ?? $icons['grid'] !!}
    </svg>
    <span :class="collapsed ? 'lg:hidden' : ''">{{ $slot }}</span>
</a>
