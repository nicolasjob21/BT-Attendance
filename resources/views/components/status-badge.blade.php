@props(['status'])

@php
$map = [
    'pending'    => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    'approved'   => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    'denied'     => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    'active'     => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    'inactive'   => 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-300',
    'on_leave'   => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    'open'       => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
    'processing' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    'closed'     => 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-300',
];
$classes = $map[$status] ?? 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-300';
@endphp

<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize {{ $classes }}">
    {{ str_replace('_', ' ', $status) }}
</span>
