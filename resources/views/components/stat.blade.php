{{-- Compact summary tile for the strip above a table: label, big value, hint. --}}
@props(['label', 'value', 'hint' => null, 'tone' => 'neutral', 'href' => null])
@php
    $tones = [
        'neutral' => 'text-gray-900 dark:text-slate-100',
        'brand' => 'text-brand-700 dark:text-brand-300',
        'success' => 'text-emerald-600 dark:text-emerald-400',
        'warn' => 'text-amber-600 dark:text-amber-400',
        'danger' => 'text-accent-600 dark:text-accent-400',
        'muted' => 'text-gray-400 dark:text-slate-500',
    ];
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'card flex min-w-0 flex-col px-4 py-3.5'.($href ? ' card-hover' : '')]) }}>
    <p class="eyebrow text-[10px]">{{ $label }}</p>
    <p class="mt-1 break-words font-display text-xl font-bold tabular-nums leading-tight sm:text-2xl {{ $tones[$tone] ?? $tones['neutral'] }}">{{ $value }}</p>
    @if($hint)<p class="mt-0.5 text-[11px] leading-snug text-gray-500 dark:text-slate-400">{{ $hint }}</p>@endif
</{{ $tag }}>
