{{-- Compact checkpoint (campaign) list. Expects site, participants_count, completed_count, non_compliant_count. --}}
@props(['campaigns', 'empty' => 'No checkpoints.'])

<div class="overflow-x-auto">
    <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
        <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400 whitespace-nowrap">
            <tr>
                <th class="px-4 py-3">Checkpoint</th>
                <th class="px-4 py-3">Project site</th>
                <th class="px-4 py-3">Start</th>
                <th class="px-4 py-3">Deadline</th>
                <th class="px-4 py-3 text-center">Employees</th>
                <th class="px-4 py-3 text-center">Completed</th>
                <th class="px-4 py-3 text-center">Not completed</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            @forelse($campaigns as $c)
                <tr class="hover:bg-gray-50/60 dark:hover:bg-slate-800/40">
                    <td class="cell-head px-4 py-3">
                        <a href="{{ route('checkpoints.show', $c) }}" class="font-medium text-gray-900 hover:underline dark:text-slate-100">{{ $c->name }}</a>
                        <div class="truncate text-xs text-gray-500 dark:text-slate-400">{{ $c->instruction }}</div>
                    </td>
                    <td data-label="Project site" class="px-4 py-3 text-gray-700 dark:text-slate-200">{{ $c->site?->name }}</td>
                    <td data-label="Start" class="px-4 py-3 whitespace-nowrap tabular-nums text-gray-700 dark:text-slate-200">
                        @if($c->starts_at) {{ $c->starts_at->format('M j, g:i A') }}
                        @elseif($c->scheduled_start_at) <span class="text-sky-700 dark:text-sky-300" title="{{ $c->isRandomlyScheduled() ? 'System-generated from '.$c->randomWindowLabel() : 'Set by admin' }}">{{ $c->scheduled_start_at->format('M j, g:i A') }}</span>@if($c->isRandomlyScheduled()) <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-slate-500">auto</span>@endif
                        @else — @endif
                    </td>
                    <td data-label="Deadline" class="px-4 py-3 whitespace-nowrap tabular-nums text-gray-700 dark:text-slate-200">{{ $c->expires_at?->format('g:i A') ?? ($c->response_window_minutes . ' min window') }}</td>
                    <td data-label="Employees" class="px-4 py-3 text-center tabular-nums">{{ $c->participants_count }}</td>
                    <td data-label="Completed" class="px-4 py-3 text-center tabular-nums text-emerald-700 dark:text-emerald-300">{{ $c->completed_count ?? '—' }}</td>
                    <td data-label="Not completed" class="px-4 py-3 text-center tabular-nums {{ ($c->non_compliant_count ?? 0) ? 'text-accent-700 dark:text-accent-300' : '' }}">{{ $c->non_compliant_count ?? '—' }}</td>
                    <td data-label="Status" class="px-4 py-3"><x-campaign-status-badge :campaign="$c" /></td>
                    <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('checkpoints.show', $c) }}"
                               class="btn-app btn-xs btn-outline-brand">{{ $c->isLive() ? 'Monitor' : 'Open' }}</a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-gray-400 dark:text-slate-500">{{ $empty }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
