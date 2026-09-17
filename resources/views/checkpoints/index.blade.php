<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Check Point</h1>
    </x-slot>

    <div class="page space-y-6">

        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <p class="max-w-2xl text-sm text-gray-500 dark:text-slate-400">
                Shared live presence checkpoints. One checkpoint = one project site, one instruction, one start time and one deadline for every selected employee. Activate only when needed.
            </p>
            <div class="flex flex-wrap gap-2">
                @can('view checkpoint results')
                    <a href="{{ route('checkpoints.results.index') }}" class="btn-app btn-md btn-secondary">All responses</a>
                @endcan
                <a href="{{ route('checkpoints.daily') }}" class="btn-app btn-md btn-outline-brand">Daily monitor</a>
                <a href="{{ route('checkpoints.history') }}" class="btn-app btn-md btn-secondary">History</a>
                @can('manage checkpoint settings')
                    <a href="{{ route('checkpoints.settings') }}" class="btn-app btn-md btn-secondary">Settings</a>
                @endcan
                @can('create checkpoint campaign')
                    <a href="{{ route('checkpoints.create') }}" class="btn-app btn-md btn-brand">+ Create Checkpoint</a>
                @endcan
            </div>
        </div>

        @php
            $cards = [
                ['Active now', $stats['active'], $stats['paused'] ? $stats['paused'] . ' paused' : 'checkpoints running', 'text-emerald-600 dark:text-emerald-400'],
                ['Employees in live checkpoints', $stats['employees_live'], $stats['completed_live'] . ' completed so far', 'text-gray-900 dark:text-slate-100'],
                ['Expired · awaiting sign-off', $stats['expired_open'], 'mark completed after follow-up', 'text-rose-600 dark:text-rose-400'],
                ['Open follow-ups', $stats['awaiting_followup'], 'non-compliant, not yet reviewed', 'text-amber-600 dark:text-amber-400'],
                ['Pending HR review', $stats['pending_review'], 'marked for review', 'text-amber-600 dark:text-amber-400'],
                ['Escalated', $stats['escalated'], 'with management', 'text-rose-600 dark:text-rose-400'],
            ];
        @endphp
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach($cards as [$label, $value, $hint, $tone])
                <div class="card p-4">
                    <p class="eyebrow text-[10px]">{{ $label }}</p>
                    <p class="mt-1.5 text-2xl font-bold tabular-nums {{ $tone }}">{{ $value }}</p>
                    <p class="text-[11px] text-gray-500 dark:text-slate-400">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <section class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Active &amp; paused checkpoints</h2>
                <span class="text-xs text-gray-500 dark:text-slate-400">{{ $live->count() }}</span>
            </div>
            <x-checkpoints.campaign-table :campaigns="$live" empty="No checkpoint is running. Create one when a site needs a presence check." />
        </section>

        @if($expired->isNotEmpty())
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Expired — follow-up in progress</h2>
                    <span class="text-xs text-gray-500 dark:text-slate-400">{{ $expired->count() }}</span>
                </div>
                <x-checkpoints.campaign-table :campaigns="$expired" />
            </section>
        @endif

        @if($drafts->isNotEmpty())
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Scheduled &amp; drafts</h2>
                    <span class="text-xs text-gray-500 dark:text-slate-400">{{ $drafts->count() }}</span>
                </div>
                <x-checkpoints.campaign-table :campaigns="$drafts" />
            </section>
        @endif

        <section class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Employees needing follow-up</h2>
                @can('view checkpoint results')
                    <a href="{{ route('checkpoints.results.index', ['follow' => 'open']) }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">View all ({{ $stats['awaiting_followup'] }})</a>
                @endcan
            </div>
            <ul class="divide-y divide-gray-100 dark:divide-slate-700">
                @forelse($followUps as $cp)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-gray-900 dark:text-slate-100">{{ $cp->employee?->full_name }} <span class="font-normal text-gray-400 dark:text-slate-500">· {{ $cp->reference() }}</span></p>
                            <p class="truncate text-xs text-gray-500 dark:text-slate-400">{{ $cp->site?->name }} · {{ $cp->campaign?->name }} · deadline {{ $cp->campaign?->expires_at?->format('M j, g:i A') }}</p>
                            @if($cp->employee_explanation)<p class="truncate text-xs text-gray-600 dark:text-slate-300">“{{ $cp->employee_explanation }}”</p>@endif
                        </div>
                        <x-checkpoint-status-badge :checkpoint="$cp" />
                        @can('view checkpoint results')
                            <a href="{{ route('checkpoints.results.show', $cp) }}" class="btn-app btn-xs btn-outline-warn">Follow up</a>
                        @endcan
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">No open follow-ups.</li>
                @endforelse
            </ul>
        </section>

        <section class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Completed &amp; cancelled</h2>
                <a href="{{ route('checkpoints.history') }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">Full history</a>
            </div>
            <x-checkpoints.campaign-table :campaigns="$recentHistory" empty="No completed checkpoints yet." />
        </section>
    </div>
</x-app-layout>
