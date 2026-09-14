@props(['status', 'verification' => null, 'compact' => false])

@php
use App\Services\GeofenceService;

// Visual weight follows the rule table in the spec: verified = calm, alternate =
// informational, anything HR must look at = warning, gps missing = neutral-warn.
$map = [
    GeofenceService::VERIFIED_LOCATION => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    GeofenceService::AUTHORIZED_ALTERNATE_LOCATION => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
    GeofenceService::OUTSIDE_AUTHORIZED_AREA => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    GeofenceService::LOW_ACCURACY => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    GeofenceService::GPS_UNAVAILABLE => 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-300',
];
$short = [
    GeofenceService::VERIFIED_LOCATION => 'Verified',
    GeofenceService::AUTHORIZED_ALTERNATE_LOCATION => 'Alt. location',
    GeofenceService::OUTSIDE_AUTHORIZED_AREA => 'Outside area',
    GeofenceService::LOW_ACCURACY => 'Low accuracy',
    GeofenceService::GPS_UNAVAILABLE => 'No GPS',
];
$classes = $map[$status] ?? 'bg-gray-100 text-gray-500 dark:bg-slate-700 dark:text-slate-400';
$label = $compact ? ($short[$status] ?? 'Unchecked') : GeofenceService::label($status);

// HR's decision on an exception overrides the visual: approved reads as OK.
if ($verification === 'approved') {
    $classes = 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200';
    $label .= ' · approved';
} elseif ($verification === 'rejected') {
    $classes = 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200';
    $label .= ' · rejected';
} elseif ($verification === 'pending') {
    $classes = 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200';
    $label .= ' · pending';
}
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium {$classes}"]) }} title="{{ GeofenceService::label($status) }}">
    {{ $label }}
</span>
