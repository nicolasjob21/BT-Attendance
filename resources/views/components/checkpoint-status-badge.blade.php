@props(['status', 'review' => null])

@php
use App\Models\Checkpoint;

$map = [
    Checkpoint::SCHEDULED => 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-300',
    Checkpoint::OPEN => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
    Checkpoint::SUBMITTED => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
    Checkpoint::VERIFIED => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    Checkpoint::FAILED => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    Checkpoint::MISSED => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    Checkpoint::EXPIRED => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    Checkpoint::PENDING_REVIEW => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    Checkpoint::CANCELLED => 'bg-gray-100 text-gray-500 dark:bg-slate-700 dark:text-slate-400',
];
$classes = $map[$status] ?? 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-300';
$label = Checkpoint::STATUSES[$status] ?? ucfirst(str_replace('_', ' ', $status));
if ($review === 'reviewed') {
    $label .= ' · reviewed';
}
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium {$classes}"]) }}>{{ $label }}</span>
