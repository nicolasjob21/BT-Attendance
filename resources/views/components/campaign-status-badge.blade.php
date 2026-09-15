@props(['status'])

@php
use App\Models\CheckpointCampaign;

$map = [
    CheckpointCampaign::DRAFT => 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-300',
    CheckpointCampaign::SCHEDULED => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
    CheckpointCampaign::ACTIVE => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    CheckpointCampaign::PAUSED => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    CheckpointCampaign::COMPLETED => 'bg-brand-100 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200',
    CheckpointCampaign::CANCELLED => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
];
$classes = $map[$status] ?? 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-slate-300';
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium {$classes}"]) }}>
    @if($status === CheckpointCampaign::ACTIVE)<span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>@endif
    {{ CheckpointCampaign::STATUSES[$status] ?? ucfirst($status) }}
</span>
