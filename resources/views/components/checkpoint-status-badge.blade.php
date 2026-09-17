@props(['checkpoint'])

@php
use App\Models\Checkpoint;

$cp = $checkpoint;
$map = [
    Checkpoint::PENDING => 'badge-neutral',
    Checkpoint::NOTIFIED => 'badge-info',
    Checkpoint::RESPONDED => 'badge-success',
    Checkpoint::APPROVED_EXCEPTION => 'badge-success',
    Checkpoint::MISSED => 'badge-danger',
    Checkpoint::OUTSIDE_GEOFENCE => 'badge-danger',
    Checkpoint::REJECTED_EXCEPTION => 'badge-danger',
    Checkpoint::GPS_UNAVAILABLE => 'badge-warn',
    Checkpoint::CAMERA_PERMISSION_DENIED => 'badge-warn',
    Checkpoint::SUBMISSION_FAILED => 'badge-warn',
    Checkpoint::PENDING_REVIEW => 'badge-warn',
];
$classes = $map[$cp->status] ?? 'badge-neutral';

// Completed rows show their verification result (Completed / …low GPS accuracy / …after review).
$label = $cp->isCompleted() && $cp->verification_result ? $cp->verification_label : $cp->status_label;
@endphp

<span {{ $attributes->merge(['class' => "badge {$classes}"]) }}>{{ $label }}</span>
