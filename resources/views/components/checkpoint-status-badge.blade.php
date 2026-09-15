@props(['checkpoint'])

@php
use App\Models\Checkpoint;

$cp = $checkpoint;
$map = [
    Checkpoint::PENDING => 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-300',
    Checkpoint::NOTIFIED => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
    Checkpoint::RESPONDED => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    Checkpoint::APPROVED_EXCEPTION => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    Checkpoint::MISSED => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    Checkpoint::OUTSIDE_GEOFENCE => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    Checkpoint::REJECTED_EXCEPTION => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    Checkpoint::GPS_UNAVAILABLE => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    Checkpoint::CAMERA_PERMISSION_DENIED => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    Checkpoint::SUBMISSION_FAILED => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    Checkpoint::PENDING_REVIEW => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
];
$classes = $map[$cp->status] ?? 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-300';

// Completed rows show their verification result (Completed / …low GPS accuracy / …after review).
$label = $cp->isCompleted() && $cp->verification_result ? $cp->verification_label : $cp->status_label;
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium {$classes}"]) }}>{{ $label }}</span>
