@props(['checkpoint'])
@php
    $cp = $checkpoint;
    if ($cp->latitude === null && $cp->last_attempt_at === null) {
        $gps = null;
    } elseif ($cp->latitude === null) {
        $gps = 'gps_unavailable';
    } elseif ($cp->last_attempt_result === 'low_gps_accuracy') {
        $gps = 'low_accuracy';
    } elseif ($cp->within_geofence) {
        $gps = 'verified_location';
    } elseif ($cp->matched_site_id) {
        $gps = 'authorized_alternate_location';
    } else {
        $gps = 'outside_authorized_area';
    }
@endphp
@if($gps)
    <x-location-badge :status="$gps" compact />
@else
    <span class="text-gray-400 dark:text-slate-500">—</span>
@endif
