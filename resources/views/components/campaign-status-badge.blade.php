@props(['campaign'])

@php
use App\Models\CheckpointCampaign;

$c = $campaign;
$map = [
    CheckpointCampaign::DRAFT => 'badge-neutral',
    CheckpointCampaign::ACTIVE => 'badge-success',
    CheckpointCampaign::PAUSED => 'badge-warn',
    CheckpointCampaign::EXPIRED => 'badge-danger',
    CheckpointCampaign::COMPLETED => 'badge-info',
    CheckpointCampaign::CANCELLED => 'badge-muted',
];
$classes = $c->isScheduled() ? 'badge-info' : ($map[$c->status] ?? 'badge-neutral');
@endphp

<span {{ $attributes->merge(['class' => "badge {$classes}"]) }}>
    @if($c->status === CheckpointCampaign::ACTIVE)<i class="dot animate-pulse"></i>@endif
    {{ $c->status_label }}
</span>
