<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Check Point · Campaign history</h1>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <select name="status" onchange="this.form.submit()" class="rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                    <option value="">Completed &amp; cancelled</option>
                    @foreach(\App\Models\CheckpointCampaign::STATUSES as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @if($status)<a href="{{ route('checkpoints.history') }}" class="text-sm text-gray-500 hover:underline dark:text-slate-400">Clear</a>@endif
            </form>
            <a href="{{ route('checkpoints.index') }}" class="text-sm text-brand-700 hover:underline dark:text-brand-300">← Back to Check Point</a>
        </div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 whitespace-nowrap dark:bg-slate-800/60 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Campaign</th>
                            <th class="px-4 py-3">Project site</th>
                            <th class="px-4 py-3">Dates</th>
                            <th class="px-4 py-3 text-center">Employees</th>
                            <th class="px-4 py-3 text-center">Verified</th>
                            <th class="px-4 py-3 text-center">Exceptions</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Closed</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($campaigns as $c)
                            <tr class="hover:bg-gray-50/60 dark:hover:bg-slate-800/40">
                                <td class="cell-head px-4 py-3">
                                    <a href="{{ route('checkpoints.show', $c) }}" class="font-medium text-gray-900 hover:underline dark:text-slate-100">{{ $c->name }}</a>
                                    <div class="text-xs text-gray-500 dark:text-slate-400">by {{ $c->creator?->name }} · {{ $c->created_at->format('M j, Y') }}</div>
                                </td>
                                <td data-label="Project site" class="px-4 py-3">{{ $c->site?->name }}</td>
                                <td data-label="Dates" class="px-4 py-3 whitespace-nowrap">{{ $c->start_date->format('M j') }} – {{ $c->end_date->format('M j, Y') }}</td>
                                <td data-label="Employees" class="px-4 py-3 text-center tabular-nums">{{ $c->participants_count }}</td>
                                <td data-label="Verified" class="px-4 py-3 text-center tabular-nums text-emerald-700 dark:text-emerald-300">{{ $c->verified_count }}</td>
                                <td data-label="Exceptions" class="px-4 py-3 text-center tabular-nums {{ $c->exception_count ? 'text-rose-700 dark:text-rose-300' : '' }}">{{ $c->exception_count }}</td>
                                <td data-label="Status" class="px-4 py-3"><x-campaign-status-badge :status="$c->status" /></td>
                                <td data-label="Closed" class="px-4 py-3 text-xs text-gray-500 dark:text-slate-400">
                                    @if($c->closed_at){{ $c->closed_at->format('M j, g:i A') }}<span class="block">{{ $c->closer?->name }}</span>@else — @endif
                                </td>
                                <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('checkpoints.show', $c) }}" class="rounded-xs border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">Open</a>
                                        @can('export checkpoint reports')
                                            <a href="{{ route('checkpoints.export', $c) }}" class="rounded-xs border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">CSV</a>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">No campaigns in history.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $campaigns->links() }}
    </div>
</x-app-layout>
