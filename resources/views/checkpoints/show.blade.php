@php
    use App\Models\Checkpoint;
    use App\Models\CheckpointCampaign;
    $c = $campaign;
    $n = fn ($k) => (int) ($counts[$k] ?? 0);
    $closed = $n(Checkpoint::VERIFIED) + $n(Checkpoint::FAILED) + $n(Checkpoint::MISSED) + $n(Checkpoint::EXPIRED) + $n(Checkpoint::PENDING_REVIEW);
    $startsToday = $c->start_date->lte(today());
@endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Check Point · {{ $c->name }}</h1>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-5">
        @if(session('status'))
            <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-xs border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-900/30 dark:text-rose-200">{{ $errors->first() }}</div>
        @endif

        {{-- Header / controls --}}
        <div class="card p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <a href="{{ route('checkpoints.index') }}" class="text-xs text-brand-700 hover:underline dark:text-brand-300">← Check Point</a>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-bold text-gray-900 dark:text-slate-100">{{ $c->name }}</h2>
                        <x-campaign-status-badge :status="$c->status" />
                    </div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-slate-300">
                        {{ $c->site?->name }} · {{ $c->start_date->format('M j') }} – {{ $c->end_date->format('M j, Y') }} ·
                        {{ \Carbon\Carbon::parse($c->working_start_time)->format('g:i A') }}–{{ \Carbon\Carbon::parse($c->working_end_time)->format('g:i A') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    @if($c->canActivate())
                        @can('create checkpoint campaign')
                            <a href="{{ route('checkpoints.edit', $c) }}" class="rounded-xs border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">Edit</a>
                        @endcan
                        @can('activate checkpoint campaign')
                            @if($c->status === CheckpointCampaign::DRAFT)
                                <x-confirm-action :action="route('checkpoints.activate', $c)" size="md" tone="brand" variant="primary"
                                    :button="$startsToday ? 'Activate now' : 'Schedule for ' . $c->start_date->format('M j')"
                                    title="Activate this checkpoint campaign?"
                                    message="Are you sure you want to activate this checkpoint campaign for the selected employees and project site? {{ $startsToday ? 'Random checkpoints for today will be generated immediately.' : 'It will start automatically on ' . $c->start_date->format('M j, Y') . '.' }}" />
                            @endif
                        @endcan
                        @can('end checkpoint campaign')
                            <x-confirm-action :action="route('checkpoints.cancel', $c)" tone="rose" size="md" button="Cancel campaign" reason
                                title="Cancel this campaign?" message="The campaign will not run. It stays in history as cancelled." />
                        @endcan
                    @endif

                    @if($c->status === CheckpointCampaign::ACTIVE)
                        @can('pause checkpoint campaign')
                            <x-confirm-action :action="route('checkpoints.pause', $c)" tone="amber" size="md" button="Pause" reason
                                title="Pause this campaign?" message="No new checkpoints will open while paused. Checkpoints that are already open keep running until they expire." />
                        @endcan
                    @endif
                    @if($c->status === CheckpointCampaign::PAUSED)
                        @can('pause checkpoint campaign')
                            <x-confirm-action :action="route('checkpoints.resume', $c)" tone="emerald" size="md" button="Resume"
                                title="Resume this campaign?" message="Remaining checkpoints for today will open at their scheduled times. Any whose time passed while paused are skipped." />
                        @endcan
                    @endif
                    @if($c->isLive())
                        @can('end checkpoint campaign')
                            <x-confirm-action :action="route('checkpoints.end', $c)" tone="rose" size="md" button="End campaign" reason
                                title="End this campaign early?" message="Pending checkpoints are cancelled. All results, photos and reviews are kept." />
                        @endcan
                    @endif
                    @if($c->status === CheckpointCampaign::COMPLETED && ! $c->closed_at)
                        @can('end checkpoint campaign')
                            <x-confirm-action :action="route('checkpoints.close', $c)" tone="brand" size="md" button="Close campaign"
                                title="Close this campaign?" message="Marks the completed campaign as reviewed and closed under your name." />
                        @endcan
                    @endif
                    @can('export checkpoint reports')
                        <a href="{{ route('checkpoints.export', $c) }}" class="rounded-xs border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">Export CSV</a>
                    @endcan
                </div>
            </div>

            @if($c->status === CheckpointCampaign::DRAFT)
                <div class="mt-4 rounded-xs border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm text-sky-800 dark:border-sky-900/50 dark:bg-sky-900/30 dark:text-sky-200">
                    <strong>Review the configuration below.</strong> Nothing is sent to employees until you activate the campaign.
                </div>
            @endif
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            {{-- Configuration --}}
            <section class="card p-5 lg:col-span-2">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Configuration</h3>
                <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-gray-500 dark:text-slate-400">Project site</dt><dd class="text-gray-900 dark:text-slate-100">{{ $c->site?->name }} <span class="text-xs text-gray-500">({{ $c->site?->geofence_radius_m }} m geofence)</span></dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-slate-400">Dates</dt><dd class="text-gray-900 dark:text-slate-100">{{ $c->start_date->format('D, M j') }} – {{ $c->end_date->format('D, M j, Y') }}{{ $c->include_weekends ? ' (incl. weekends)' : ' (weekdays only)' }}</dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-slate-400">Working hours</dt><dd class="text-gray-900 dark:text-slate-100">{{ \Carbon\Carbon::parse($c->working_start_time)->format('g:i A') }} – {{ \Carbon\Carbon::parse($c->working_end_time)->format('g:i A') }}</dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-slate-400">Checkpoints</dt><dd class="text-gray-900 dark:text-slate-100">{{ $c->checkpoints_per_day }} per day · {{ $c->minimum_interval_minutes }}–{{ $c->maximum_interval_minutes }} min apart · {{ $c->response_window_minutes }}-min response window</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs text-gray-500 dark:text-slate-400">Reason for activation</dt><dd class="text-gray-900 dark:text-slate-100">“{{ $c->reason }}”</dd></div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-gray-500 dark:text-slate-400">Photo instructions (rotating)</dt>
                        <dd class="mt-1 flex flex-wrap gap-1.5">
                            @foreach($c->photo_instructions as $i)
                                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-700 dark:bg-slate-700 dark:text-slate-200">{{ $i }}</span>
                            @endforeach
                        </dd>
                    </div>
                    <div><dt class="text-xs text-gray-500 dark:text-slate-400">Created by</dt><dd class="text-gray-900 dark:text-slate-100">{{ $c->creator?->name ?? '—' }} · {{ $c->created_at->format('M j, g:i A') }}</dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-slate-400">Activated by</dt><dd class="text-gray-900 dark:text-slate-100">{{ $c->activator?->name ?? '—' }}@if($c->activated_at) · {{ $c->activated_at->format('M j, g:i A') }}@endif</dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-slate-400">Closed by</dt><dd class="text-gray-900 dark:text-slate-100">{{ $c->closer?->name ?? '—' }}@if($c->closed_at) · {{ $c->closed_at->format('M j, g:i A') }}@endif</dd></div>
                    @if($c->isActive())
                        <div><dt class="text-xs text-gray-500 dark:text-slate-400">Queued today</dt><dd class="text-gray-900 dark:text-slate-100">{{ $upcomingToday }} checkpoint(s) still to open <span class="text-xs text-gray-500">(times hidden)</span></dd></div>
                    @endif
                </dl>
            </section>

            {{-- Employees & counters --}}
            <section class="card p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Results</h3>
                <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-xs bg-emerald-50 p-2 dark:bg-emerald-900/20"><p class="text-lg font-bold text-emerald-700 dark:text-emerald-300">{{ $n(Checkpoint::VERIFIED) }}</p><p class="text-[10px] uppercase tracking-wide text-emerald-800/70 dark:text-emerald-200/70">Verified</p></div>
                    <div class="rounded-xs bg-rose-50 p-2 dark:bg-rose-900/20"><p class="text-lg font-bold text-rose-700 dark:text-rose-300">{{ $n(Checkpoint::MISSED) + $n(Checkpoint::EXPIRED) }}</p><p class="text-[10px] uppercase tracking-wide text-rose-800/70 dark:text-rose-200/70">Missed</p></div>
                    <div class="rounded-xs bg-rose-50 p-2 dark:bg-rose-900/20"><p class="text-lg font-bold text-rose-700 dark:text-rose-300">{{ $n(Checkpoint::FAILED) }}</p><p class="text-[10px] uppercase tracking-wide text-rose-800/70 dark:text-rose-200/70">Failed</p></div>
                    <div class="rounded-xs bg-amber-50 p-2 dark:bg-amber-900/20"><p class="text-lg font-bold text-amber-700 dark:text-amber-300">{{ $n(Checkpoint::PENDING_REVIEW) }}</p><p class="text-[10px] uppercase tracking-wide text-amber-800/70 dark:text-amber-200/70">Pending</p></div>
                    <div class="rounded-xs bg-sky-50 p-2 dark:bg-sky-900/20"><p class="text-lg font-bold text-sky-700 dark:text-sky-300">{{ $n(Checkpoint::OPEN) }}</p><p class="text-[10px] uppercase tracking-wide text-sky-800/70 dark:text-sky-200/70">Open</p></div>
                    <div class="rounded-xs bg-slate-100 p-2 dark:bg-slate-700/40"><p class="text-lg font-bold text-slate-700 dark:text-slate-200">{{ $closed }}</p><p class="text-[10px] uppercase tracking-wide text-slate-600/70 dark:text-slate-300/70">Closed</p></div>
                </div>

                <h3 class="mt-5 text-sm font-semibold text-gray-900 dark:text-slate-100">Employees covered <span class="font-normal text-gray-500">({{ $c->employees->count() }})</span></h3>
                <ul class="mt-2 max-h-64 divide-y divide-gray-100 overflow-y-auto text-sm dark:divide-slate-700">
                    @foreach($c->employees as $e)
                        <li class="flex items-center justify-between py-1.5">
                            <span class="text-gray-900 dark:text-slate-100">{{ $e->full_name }}</span>
                            <span class="text-xs text-gray-500 dark:text-slate-400">{{ $e->employee_no }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>

        {{-- Checkpoint results --}}
        <section class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Checkpoints</h3>
                @can('view checkpoint results')
                    <a href="{{ route('checkpoints.results.index', ['campaign' => $c->id]) }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">Filter &amp; search</a>
                @endcan
            </div>
            <x-checkpoints.activity-table :checkpoints="$checkpoints" :show-campaign="false" empty="No checkpoints have opened yet. Upcoming times are hidden by design." />
            @if($checkpoints->hasPages())
                <div class="border-t border-gray-100 px-4 py-3 dark:border-slate-700">{{ $checkpoints->links() }}</div>
            @endif
        </section>

        {{-- Audit log --}}
        <section class="card p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Audit log</h3>
            <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-slate-700">
                @forelse($audit as $a)
                    <li class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-2">
                        <span class="w-36 shrink-0 text-xs tabular-nums text-gray-500 dark:text-slate-400">{{ $a->created_at->format('M j, g:i:s A') }}</span>
                        <span class="font-medium text-gray-900 dark:text-slate-100">{{ $a->action_label }}</span>
                        <span class="text-xs text-gray-500 dark:text-slate-400">{{ $a->user?->name ?? 'System' }}@if($a->checkpoint_id) · CP-{{ str_pad($a->checkpoint_id, 6, '0', STR_PAD_LEFT) }}@endif</span>
                        @if($a->details)
                            <span class="w-full text-xs text-gray-500 dark:text-slate-400 sm:w-auto">
                                @foreach($a->details as $k => $v)<span class="mr-2">{{ $k }}: {{ is_scalar($v) ? $v : json_encode($v) }}</span>@endforeach
                            </span>
                        @endif
                    </li>
                @empty
                    <li class="py-4 text-center text-gray-400 dark:text-slate-500">No actions yet.</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-app-layout>
