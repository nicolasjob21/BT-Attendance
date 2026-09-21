<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Check Point</h1>
    </x-slot>

    <div class="page space-y-5">

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-gray-500 dark:text-slate-400">
                A checkpoint asks every employee on a project site to prove they are there. Open one to see who completed it and who did not.
            </p>
            <div class="flex flex-wrap gap-2">
                @can('manage checkpoint settings')
                    <a href="{{ route('checkpoints.settings') }}" class="btn-app btn-md btn-secondary">Settings</a>
                @endcan
                @can('create checkpoint campaign')
                    <a href="{{ route('checkpoints.create') }}" class="btn-app btn-md btn-brand">+ Create Checkpoint</a>
                @endcan
            </div>
        </div>

        <div class="stat-strip">
            <x-stat label="Running now" :value="$stats['live']" :hint="$stats['live'] ? 'employees are being asked' : 'nothing live'" :tone="$stats['live'] ? 'success' : 'muted'" />
            <x-stat label="Scheduled" :value="$stats['scheduled']" hint="will fire on their own" :tone="$stats['scheduled'] ? 'brand' : 'muted'" />
            <x-stat label="Completed today" :value="$stats['today_done']" :hint="'of '.$stats['today'].' asked today'" :tone="$stats['today'] && $stats['today_done'] === $stats['today'] ? 'success' : 'neutral'" />
            <x-stat label="Not completed today" :value="$stats['today'] - $stats['today_done']" :tone="($stats['today'] - $stats['today_done']) ? 'danger' : 'muted'" />
        </div>

        <section class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700">
                    <thead class="whitespace-nowrap bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-slate-800/60 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Checkpoint</th>
                            <th class="px-4 py-3">Project</th>
                            <th class="px-4 py-3">When</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Completed</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($campaigns as $c)
                            @php
                                $total = $c->participants_count;
                                $done = (int) $c->completed_count;
                                $pct = $total ? (int) round($done / $total * 100) : 0;
                            @endphp
                            <tr class="hover:bg-gray-50/60 dark:hover:bg-slate-800/40">
                                <td class="cell-head px-4 py-3">
                                    <a href="{{ route('checkpoints.show', $c) }}" class="font-medium text-gray-900 hover:underline dark:text-slate-100">{{ $c->name }}</a>
                                </td>
                                <td data-label="Project" class="px-4 py-3 text-gray-700 dark:text-slate-200">{{ $c->site?->name }}</td>
                                <td data-label="When" class="px-4 py-3 whitespace-nowrap tabular-nums text-gray-700 dark:text-slate-200">
                                    @if($c->starts_at)
                                        {{ $c->starts_at->format('M j · g:i A') }}
                                    @elseif($c->scheduled_start_at)
                                        {{ $c->scheduled_start_at->format('M j · g:i A') }}
                                    @else
                                        <span class="text-gray-400 dark:text-slate-500">Not scheduled</span>
                                    @endif
                                </td>
                                <td data-label="Status" class="px-4 py-3"><x-campaign-status-badge :campaign="$c" /></td>
                                <td data-label="Completed" class="px-4 py-3">
                                    @if($c->isDraft())
                                        <span class="text-gray-400 dark:text-slate-500">{{ $total }} employee(s)</span>
                                    @else
                                        <div class="flex items-center gap-3">
                                            <span class="tabular-nums font-semibold {{ $done === $total ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-900 dark:text-slate-100' }}">{{ $done }} <span class="font-normal text-gray-500 dark:text-slate-400">of {{ $total }}</span></span>
                                            <span class="hidden h-1.5 w-24 overflow-hidden bg-gray-200 sm:block dark:bg-slate-700" title="{{ $pct }}%"><span class="block h-full bg-emerald-500" style="width: {{ $pct }}%"></span></span>
                                        </div>
                                    @endif
                                </td>
                                <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                                    <div class="row-actions">
                                        <a href="{{ route('checkpoints.show', $c) }}" class="is-primary">{{ $c->isLive() ? 'Monitor' : 'Open' }}</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-0"><x-empty-state icon="shield" title="No checkpoints yet" hint="Create one when a project site needs a presence check." :href="auth()->user()->can('create checkpoint campaign') ? route('checkpoints.create') : null" action="Create checkpoint" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($campaigns->hasPages())
                <div class="border-t border-gray-100 px-4 py-3 dark:border-slate-700">{{ $campaigns->links() }}</div>
            @endif
        </section>
    </div>
</x-app-layout>
