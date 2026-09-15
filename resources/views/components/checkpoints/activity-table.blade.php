{{-- Checkpoint activity rows component. --}}
@props(['checkpoints', 'showCampaign' => true, 'empty' => 'No checkpoint activity yet.'])

<div class="overflow-x-auto">
    <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
        <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400 whitespace-nowrap">
            <tr>
                <th class="px-4 py-3">Employee</th>
                <th class="px-4 py-3">Project site</th>
                @if($showCampaign)<th class="px-4 py-3">Campaign</th>@endif
                <th class="px-4 py-3">Checkpoint</th>
                <th class="px-4 py-3">Submitted</th>
                <th class="px-4 py-3">GPS</th>
                <th class="px-4 py-3 text-right">Distance</th>
                <th class="px-4 py-3">Photo</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Review</th>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            @forelse($checkpoints as $cp)
                <tr class="hover:bg-gray-50/60 dark:hover:bg-slate-800/40">
                    <td class="cell-head px-4 py-3">
                        <div class="font-medium text-gray-900 dark:text-slate-100">{{ $cp->employee?->full_name }}</div>
                        <div class="text-xs text-gray-500 dark:text-slate-400">{{ $cp->employee?->employee_no }} · {{ $cp->reference() }}</div>
                    </td>
                    <td data-label="Project site" class="px-4 py-3 text-gray-700 dark:text-slate-200">{{ $cp->site?->name }}</td>
                    @if($showCampaign)
                        <td data-label="Campaign" class="px-4 py-3 text-gray-700 dark:text-slate-200">
                            <a href="{{ route('checkpoints.show', $cp->campaign_id) }}" class="hover:underline">{{ $cp->campaign?->name }}</a>
                        </td>
                    @endif
                    <td data-label="Checkpoint" class="px-4 py-3 whitespace-nowrap tabular-nums text-gray-700 dark:text-slate-200">
                        {{ $cp->scheduled_at->format('M j, g:i A') }}
                        @if($cp->expires_at)<span class="block text-[11px] text-gray-400 dark:text-slate-500">until {{ $cp->expires_at->format('g:i A') }}</span>@endif
                    </td>
                    <td data-label="Submitted" class="px-4 py-3 whitespace-nowrap tabular-nums text-gray-700 dark:text-slate-200">
                        {{ $cp->submitted_at?->format('g:i:s A') ?? '—' }}
                    </td>
                    <td data-label="GPS" class="px-4 py-3">
                        @if($cp->hasSubmission() || $cp->latitude !== null)
                            @php $gps = $cp->latitude === null ? 'gps_unavailable' : ($cp->failure_reason === 'low_gps_accuracy' ? 'low_accuracy' : ($cp->within_geofence ? 'verified_location' : ($cp->matched_site_id ? 'authorized_alternate_location' : 'outside_authorized_area'))); @endphp
                            <x-location-badge :status="$gps" compact />
                        @else
                            <span class="text-gray-400 dark:text-slate-500">—</span>
                        @endif
                    </td>
                    <td data-label="Distance" class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-slate-200">
                        {{ $cp->distance_from_site_meters !== null ? number_format((float) $cp->distance_from_site_meters) . ' m' : '—' }}
                    </td>
                    <td data-label="Photo" class="px-4 py-3">
                        @if($cp->photo_path)
                            <a href="{{ route('checkpoints.photo', $cp) }}" target="_blank" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">View</a>
                        @else
                            <span class="text-xs text-gray-400 dark:text-slate-500">None</span>
                        @endif
                    </td>
                    <td data-label="Status" class="px-4 py-3">
                        <x-checkpoint-status-badge :status="$cp->verification_status" />
                        @if($cp->result_label && $cp->verification_status !== \App\Models\Checkpoint::VERIFIED)
                            <span class="block text-[11px] text-gray-500 dark:text-slate-400">{{ $cp->result_label }}</span>
                        @endif
                    </td>
                    <td data-label="Review" class="px-4 py-3 text-xs">
                        @if($cp->review_status === 'reviewed')
                            <span class="font-medium text-gray-700 dark:text-slate-200">{{ $cp->review_result_label }}</span>
                            <span class="block text-[11px] text-gray-400 dark:text-slate-500">{{ $cp->reviewer?->name }}</span>
                        @elseif($cp->review_status === 'pending')
                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Needs review</span>
                        @else
                            <span class="text-gray-400 dark:text-slate-500">—</span>
                        @endif
                    </td>
                    <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                        <div class="flex items-center justify-end gap-1">
                            @can('view checkpoint results')
                                <a href="{{ route('checkpoints.results.show', $cp) }}"
                                   class="rounded-xs border px-2.5 py-1 text-xs font-medium {{ $cp->needsReview() ? 'border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-900/30' : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60' }}">
                                    {{ $cp->needsReview() ? 'Review' : 'Details' }}
                                </a>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $showCampaign ? 11 : 10 }}" class="px-4 py-8 text-center text-gray-400 dark:text-slate-500">{{ $empty }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
