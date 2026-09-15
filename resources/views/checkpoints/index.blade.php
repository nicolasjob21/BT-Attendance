<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Check Point</h1>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6">
        @if(session('status'))
            <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200">
                {{ session('status') }}
            </div>
        @endif

        {{-- Toolbar --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <p class="max-w-2xl text-sm text-gray-500 dark:text-slate-400">
                Random live presence checks for a project site. Activate a campaign only when there is a concern that staff leave the site during working hours — nothing runs automatically.
            </p>
            <div class="flex flex-wrap gap-2">
                @can('view checkpoint results')
                    <a href="{{ route('checkpoints.results.index') }}" class="rounded-xs border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">All results</a>
                @endcan
                <a href="{{ route('checkpoints.history') }}" class="rounded-xs border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">History</a>
                @can('manage checkpoint settings')
                    <a href="{{ route('checkpoints.settings') }}" class="rounded-xs border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">Settings</a>
                @endcan
                @can('create checkpoint campaign')
                    <a href="{{ route('checkpoints.create') }}" class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-4 py-2 text-center text-sm font-medium text-white hover:from-brand-700 hover:to-accent-600">+ Create Checkpoint Campaign</a>
                @endcan
            </div>
        </div>

        {{-- Summary cards --}}
        @php
            $cards = [
                ['Active campaigns', $stats['active_campaigns'], $stats['paused_campaigns'] ? $stats['paused_campaigns'] . ' paused' : 'none paused', 'text-emerald-600 dark:text-emerald-400'],
                ['Employees covered', $stats['employees'], 'in live campaigns', 'text-gray-900 dark:text-slate-100'],
                ['Checkpoints generated', $stats['generated'], $stats['open'] . ' open right now', 'text-gray-900 dark:text-slate-100'],
                ['Verified', $stats['verified'], $stats['generated'] ? round($stats['verified'] / max(1, $stats['generated'] - $stats['open']) * 100) . '% of closed' : '—', 'text-emerald-600 dark:text-emerald-400'],
                ['Missed', $stats['missed'], 'no response in window', 'text-rose-600 dark:text-rose-400'],
                ['Failed', $stats['failed'], 'outside geofence', 'text-rose-600 dark:text-rose-400'],
                ['Pending reviews', $stats['pending_reviews'], 'all campaigns', 'text-amber-600 dark:text-amber-400'],
                ['Repeat exceptions', $repeat->count(), 'employees, last 30 days', 'text-amber-600 dark:text-amber-400'],
            ];
        @endphp
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
            @foreach($cards as [$label, $value, $hint, $tone])
                <div class="card p-4">
                    <p class="eyebrow text-[10px]">{{ $label }}</p>
                    <p class="mt-1.5 text-2xl font-bold tabular-nums {{ $tone }}">{{ $value }}</p>
                    <p class="text-[11px] text-gray-500 dark:text-slate-400">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        {{-- Active / paused campaigns --}}
        <section class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Active &amp; paused campaigns</h2>
                <span class="text-xs text-gray-500 dark:text-slate-400">{{ $live->count() }}</span>
            </div>
            <x-checkpoints.campaign-table :campaigns="$live" empty="No campaign is running. Create one when a site needs monitoring." />
        </section>

        {{-- Scheduled & drafts --}}
        @if($scheduled->isNotEmpty())
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Scheduled &amp; drafts</h2>
                    <span class="text-xs text-gray-500 dark:text-slate-400">{{ $scheduled->count() }}</span>
                </div>
                <x-checkpoints.campaign-table :campaigns="$scheduled" />
            </section>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Pending review --}}
            <section class="card overflow-hidden lg:col-span-2">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Exceptions requiring review</h2>
                    @can('view checkpoint results')
                        <a href="{{ route('checkpoints.results.index', ['review' => 'pending']) }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">View all ({{ $stats['pending_reviews'] }})</a>
                    @endcan
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-slate-700">
                    @forelse($pending as $cp)
                        <li class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-gray-900 dark:text-slate-100">{{ $cp->employee?->full_name }} <span class="font-normal text-gray-400 dark:text-slate-500">· {{ $cp->reference() }}</span></p>
                                <p class="truncate text-xs text-gray-500 dark:text-slate-400">{{ $cp->site?->name }} · {{ $cp->campaign?->name }} · {{ $cp->scheduled_at->format('M j, g:i A') }}</p>
                                @if($cp->employee_explanation)
                                    <p class="truncate text-xs text-gray-600 dark:text-slate-300">“{{ $cp->employee_explanation }}”</p>
                                @endif
                            </div>
                            <x-checkpoint-status-badge :status="$cp->verification_status" />
                            @can('view checkpoint results')
                                <a href="{{ route('checkpoints.results.show', $cp) }}" class="rounded-xs border border-amber-300 px-2.5 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-900/30">Review</a>
                            @endcan
                        </li>
                    @empty
                        <li class="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">Nothing to review.</li>
                    @endforelse
                </ul>
            </section>

            {{-- Repeat failures --}}
            <section class="card overflow-hidden">
                <div class="border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Employees with repeated exceptions</h2>
                    <p class="text-[11px] text-gray-500 dark:text-slate-400">2+ missed / failed / pending in the last 30 days</p>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-slate-700">
                    @forelse($repeat as $r)
                        <li class="flex items-center justify-between px-4 py-2.5 text-sm">
                            <div>
                                <p class="font-medium text-gray-900 dark:text-slate-100">{{ $r->employee?->full_name }}</p>
                                <p class="text-xs text-gray-500 dark:text-slate-400">{{ $r->employee?->employee_no }}</p>
                            </div>
                            @can('view checkpoint results')
                                <a href="{{ route('checkpoints.results.index', ['employee' => $r->employee_id]) }}" class="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-800 hover:bg-rose-200 dark:bg-rose-900/40 dark:text-rose-200">{{ $r->exceptions }}</a>
                            @else
                                <span class="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">{{ $r->exceptions }}</span>
                            @endcan
                        </li>
                    @empty
                        <li class="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">No repeat exceptions.</li>
                    @endforelse
                </ul>
            </section>
        </div>

        {{-- Latest activity --}}
        <section class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Latest checkpoint activity</h2>
                @can('view checkpoint results')
                    <a href="{{ route('checkpoints.results.index') }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">All results</a>
                @endcan
            </div>
            <x-checkpoints.activity-table :checkpoints="$activity" />
        </section>

        {{-- Recent history --}}
        <section class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Completed &amp; cancelled</h2>
                <a href="{{ route('checkpoints.history') }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">Full history</a>
            </div>
            <x-checkpoints.campaign-table :campaigns="$recentHistory" empty="No completed campaigns yet." />
        </section>
    </div>
</x-app-layout>
