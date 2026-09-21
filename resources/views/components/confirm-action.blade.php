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
    'emerald' => 'btn-success',
    'amber' => 'btn-outline-warn',
    'rose' => 'btn-danger',
    default => 'btn-brand',
};
$trigger = match ($tone) {
    'emerald' => 'btn-outline-success',
    'amber' => 'btn-outline-warn',
    'rose' => 'btn-outline-danger',
    default => 'btn-outline-brand',
};
$sizeClass = $size === 'md' ? 'btn-md' : 'btn-xs';
$triggerClasses = $variant === 'primary'
    ? "btn-app {$sizeClass} btn-brand"
    : "btn-app {$sizeClass} {$trigger}";
@endphp

<div x-data="{ open: false }" class="inline-block {{ str_contains($attributes->get('class', ''), 'w-full') ? 'w-full sm:w-auto' : '' }}">
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
                            class="btn-app btn-md btn-secondary">Cancel</button>
                    <button type="submit" class="btn-app btn-md {{ $btn }}">{{ $button }}</button>
                </div>
            </form>
        </div>
    </template>
</div>
