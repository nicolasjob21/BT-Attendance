@props(['status', 'verification' => null, 'compact' => false])

@php
use App\Services\GeofenceService;

// Visual weight follows the rule table in the spec: verified = calm, alternate =
// informational, anything HR must look at = warning, gps missing = neutral-warn.
$map = [
    GeofenceService::VERIFIED_LOCATION => 'badge-success',
    GeofenceService::AUTHORIZED_ALTERNATE_LOCATION => 'badge-info',
    GeofenceService::OUTSIDE_AUTHORIZED_AREA => 'badge-danger',
    GeofenceService::LOW_ACCURACY => 'badge-warn',
    GeofenceService::GPS_UNAVAILABLE => 'badge-neutral',
];
$short = [
    GeofenceService::VERIFIED_LOCATION => 'Verified',
    GeofenceService::AUTHORIZED_ALTERNATE_LOCATION => 'Alt. location',
    GeofenceService::OUTSIDE_AUTHORIZED_AREA => 'Outside area',
    GeofenceService::LOW_ACCURACY => 'Low accuracy',
    GeofenceService::GPS_UNAVAILABLE => 'No GPS',
];
$classes = $map[$status] ?? 'badge-muted';
$label = $compact ? ($short[$status] ?? 'Unchecked') : GeofenceService::label($status);

// HR's decision on an exception overrides the visual: approved reads as OK.
if ($verification === 'approved') {
    $classes = 'badge-success';
    $label .= ' · approved';
} elseif ($verification === 'rejected') {
    $classes = 'badge-danger';
    $label .= ' · rejected';
} elseif ($verification === 'pending') {
    $classes = 'badge-warn';
    $label .= ' · pending';
}
@endphp

<span {{ $attributes->merge(['class' => "badge {$classes}"]) }} title="{{ GeofenceService::label($status) }}">
    {{ $label }}
</span>
