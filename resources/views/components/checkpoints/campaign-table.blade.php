{{-- Compact campaign list component. --}}
@props(['campaigns', 'empty' => 'No campaigns.'])

<div class="overflow-x-auto">
    <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
        <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400 whitespace-nowrap">
            <tr>
                <th class="px-4 py-3">Campaign</th>
                <th class="px-4 py-3">Project site</th>
                <th class="px-4 py-3">Dates</th>
                <th class="px-4 py-3">Window</th>
                <th class="px-4 py-3 text-center">Employees</th>
                <th class="px-4 py-3 text-center">Per day</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            @forelse($campaigns as $c)
                <tr class="hover:bg-gray-50/60 dark:hover:bg-slate-800/40">
                    <td class="cell-head px-4 py-3">
                        <a href="{{ route('checkpoints.show', $c) }}" class="font-medium text-gray-900 hover:underline dark:text-slate-100">{{ $c->name }}</a>
                        <div class="text-xs text-gray-500 dark:text-slate-400">#{{ $c->id }} · created {{ $c->created_at->format('M j') }}@if($c->closer) · closed by {{ $c->closer->name }}@endif</div>
                    </td>
                    <td data-label="Project site" class="px-4 py-3 text-gray-700 dark:text-slate-200">{{ $c->site?->name }}</td>
                    <td data-label="Dates" class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-slate-200">
                        {{ $c->start_date->format('M j') }} – {{ $c->end_date->format('M j, Y') }}
                    </td>
                    <td data-label="Window" class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-slate-200">
                        {{ \Carbon\Carbon::parse($c->working_start_time)->format('g:i A') }} – {{ \Carbon\Carbon::parse($c->working_end_time)->format('g:i A') }}
                    </td>
                    <td data-label="Employees" class="px-4 py-3 text-center tabular-nums">{{ $c->participants_count }}</td>
                    <td data-label="Per day" class="px-4 py-3 text-center tabular-nums">{{ $c->checkpoints_per_day }}</td>
                    <td data-label="Status" class="px-4 py-3"><x-campaign-status-badge :status="$c->status" /></td>
                    <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('checkpoints.show', $c) }}"
                               class="rounded-xs border border-brand-300 px-2.5 py-1 text-xs font-medium text-brand-700 hover:bg-brand-50 dark:border-brand-500/40 dark:text-brand-300 dark:hover:bg-brand-500/10">Open</a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400 dark:text-slate-500">{{ $empty }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
