{{-- TABLE 1: employees who completed the shared checkpoint. --}}
@props(['rows', 'campaign' => null])

<div class="overflow-x-auto">
    <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
        <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400 whitespace-nowrap">
            <tr>
                <th class="px-4 py-3">Employee</th>
                <th class="px-4 py-3">Project site</th>
                <th class="px-4 py-3">Start</th>
                <th class="px-4 py-3">Submitted</th>
                <th class="px-4 py-3 text-right">Response</th>
                <th class="px-4 py-3">GPS</th>
                <th class="px-4 py-3 text-right">Accuracy</th>
                <th class="px-4 py-3 text-right">Distance</th>
                <th class="px-4 py-3">Photo</th>
                <th class="px-4 py-3">Verification</th>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            @forelse($rows as $cp)
                @php $c = $campaign ?? $cp->campaign; $secs = $cp->responseSeconds(); @endphp
                <tr class="hover:bg-gray-50/60 dark:hover:bg-slate-800/40">
                    <td class="cell-head px-4 py-3">
                        <div class="font-medium text-gray-900 dark:text-slate-100">{{ $cp->employee?->full_name }}</div>
                        <div class="text-xs text-gray-500 dark:text-slate-400">{{ $cp->employee?->employee_no }} · {{ $cp->reference() }}</div>
                    </td>
                    <td data-label="Project site" class="px-4 py-3 text-gray-700 dark:text-slate-200">{{ $cp->site?->name ?? $c?->site?->name }}</td>
                    <td data-label="Start" class="px-4 py-3 whitespace-nowrap tabular-nums text-gray-700 dark:text-slate-200">{{ $c?->starts_at?->format('g:i A') ?? '—' }}</td>
                    <td data-label="Submitted" class="px-4 py-3 whitespace-nowrap tabular-nums text-gray-700 dark:text-slate-200">{{ $cp->submitted_at?->format('g:i:s A') ?? ($cp->reviewed_at ? 'Approved ' . $cp->reviewed_at->format('g:i A') : '—') }}</td>
                    <td data-label="Response" class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-slate-200">{{ $secs !== null ? intdiv($secs, 60) . 'm ' . ($secs % 60) . 's' : '—' }}</td>
                    <td data-label="GPS" class="px-4 py-3"><x-checkpoints.gps-badge :checkpoint="$cp" /></td>
                    <td data-label="Accuracy" class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-slate-200">{{ $cp->gps_accuracy_meters !== null ? '±' . number_format((float) $cp->gps_accuracy_meters) . ' m' : '—' }}</td>
                    <td data-label="Distance" class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-slate-200">{{ $cp->distance_from_site_meters !== null ? number_format((float) $cp->distance_from_site_meters) . ' m' : '—' }}</td>
                    <td data-label="Photo" class="px-4 py-3 text-xs">
                        @if($cp->photo_path)
                            <a href="{{ route('checkpoints.photo', $cp) }}" target="_blank" class="font-medium text-emerald-700 hover:underline dark:text-emerald-300">Live photo ✓</a>
                        @else
                            <span class="text-gray-400 dark:text-slate-500">None</span>
                        @endif
                    </td>
                    <td data-label="Verification" class="px-4 py-3"><x-checkpoint-status-badge :checkpoint="$cp" /></td>
                    <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                        <div class="flex justify-end">
                            @can('view checkpoint results')
                                <a href="{{ route('checkpoints.results.show', $cp) }}" class="rounded-xs border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">View details</a>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="px-4 py-8 text-center text-gray-400 dark:text-slate-500">No completed submissions yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
