@props(['status'])

@php
$map = [
    'pending'    => 'badge-warn',
    'approved'   => 'badge-success',
    'denied'     => 'badge-danger',
    'active'     => 'badge-success',
    'inactive'   => 'badge-neutral',
    'on_leave'   => 'badge-warn',
    'open'       => 'badge-info',
    'processing' => 'badge-warn',
    'closed'     => 'badge-neutral',
    'completed'  => 'badge-info',
    'ended'      => 'badge-neutral',
    'cancelled'  => 'badge-danger',
];
$classes = $map[$status] ?? 'badge-neutral';
@endphp

<span {{ $attributes->merge(['class' => "badge {$classes}"]) }}>
    {{ str_replace('_', ' ', $status) }}
</span>
