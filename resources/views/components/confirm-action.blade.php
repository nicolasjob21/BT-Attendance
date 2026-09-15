{{--
    Button that opens a confirmation dialog before POSTing to $action.
    Optional reason textarea (name="reason"). Usage:

    <x-confirm-action :action="route('checkpoints.pause', $c)" title="Pause campaign?"
        message="No new checkpoints will open until it is resumed." button="Pause" tone="amber" reason />
--}}
@props([
    'action',
    'title',
    'message',
    'button' => 'Confirm',
    'tone' => 'brand',     // brand | emerald | amber | rose
    'reason' => false,
    'reasonLabel' => 'Reason (optional)',
    'method' => 'POST',
    'size' => 'sm',
    'variant' => 'outline', // outline | primary (gradient trigger)
])

@php
$btn = match ($tone) {
    'emerald' => 'bg-emerald-600 text-white hover:bg-emerald-700',
    'amber' => 'bg-amber-500 text-white hover:bg-amber-600',
    'rose' => 'bg-rose-600 text-white hover:bg-rose-700',
    default => 'bg-linear-to-r from-brand-600 to-accent-500 text-white hover:from-brand-700 hover:to-accent-600',
};
$trigger = match ($tone) {
    'emerald' => 'border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-900/30',
    'amber' => 'border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-900/30',
    'rose' => 'border-rose-300 text-rose-700 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-300 dark:hover:bg-rose-900/30',
    default => 'border-brand-300 text-brand-700 hover:bg-brand-50 dark:border-brand-500/40 dark:text-brand-300 dark:hover:bg-brand-500/10',
};
$pad = $size === 'md' ? 'px-4 py-2 text-sm' : 'px-2.5 py-1 text-xs';
$triggerClasses = $variant === 'primary'
    ? "rounded-xs font-semibold text-white bg-linear-to-r from-brand-600 to-accent-500 hover:from-brand-700 hover:to-accent-600 {$pad}"
    : "rounded-xs border font-medium {$pad} {$trigger}";
@endphp

<div x-data="{ open: false }" class="inline-block">
    <button type="button" @click="open = true" {{ $attributes->merge(['class' => $triggerClasses]) }}>{{ $button }}</button>

    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center" @keydown.escape.window="open = false">
            <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="open = false" x-transition.opacity></div>
            <form method="POST" action="{{ $action }}" x-show="open" x-transition
                  class="relative w-full max-w-md rounded-xs border border-gray-200 bg-white p-5 shadow-2xl dark:border-hair dark:bg-surface">
                @csrf
                @if(strtoupper($method) !== 'POST') @method($method) @endif
                <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">{{ $title }}</h3>
                <p class="mt-1.5 text-sm text-gray-600 dark:text-slate-300">{{ $message }}</p>
                @if($reason)
                    <label class="mt-3 block text-xs font-medium text-gray-600 dark:text-slate-300">{{ $reasonLabel }}</label>
                    <textarea name="reason" rows="2" maxlength="500"
                              class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600"></textarea>
                @endif
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" @click="open = false"
                            class="rounded-xs border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">Cancel</button>
                    <button type="submit" class="rounded-xs px-4 py-2 text-sm font-semibold {{ $btn }}">{{ $button }}</button>
                </div>
            </form>
        </div>
    </template>
</div>
