@php
    use App\Models\CheckpointCampaign;
    $c = $campaign;
    $live = $c->isLive();
@endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Check Point · {{ $c->isDraft() ? 'Review' : 'Monitoring' }}</h1>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-5"
         x-data="monitor({ live: @js($live), status: @js($c->status), expiresAt: @js($c->expires_at?->toIso8601String()), serverNow: @js(now()->toIso8601String()), statusUrl: @js(route('checkpoints.status', $c)) })" x-init="init()">
        @if(session('status'))
            <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-xs border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-900/30 dark:text-rose-200">{{ $errors->first() }}</div>
        @endif

        {{-- Header: shared checkpoint info + controls --}}
        <div class="card p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <a href="{{ route('checkpoints.index') }}" class="text-xs text-brand-700 hover:underline dark:text-brand-300">← Check Point</a>
                    @if($c->starts_at)<span class="text-xs text-gray-400"> · </span><a href="{{ route('checkpoints.daily', ['date' => $c->starts_at->toDateString(), 'site' => $c->project_site_id]) }}" class="text-xs text-brand-700 hover:underline dark:text-brand-300">All checkpoints on {{ $c->starts_at->format('M j') }} at this site</a>@endif
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-bold text-gray-900 dark:text-slate-100">{{ $c->name }}</h2>
                        <x-campaign-status-badge :campaign="$c" />
                    </div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-slate-300">{{ $c->site?->name }} · <span class="font-medium text-gray-800 dark:text-slate-100">“{{ $c->instruction }}”</span></p>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    @if($c->isDraft())
                        @can('create checkpoint campaign')
                            <a href="{{ route('checkpoints.edit', $c) }}" class="rounded-xs border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">Edit</a>
                        @endcan
                        @can('activate checkpoint campaign')
                            <x-confirm-action :action="route('checkpoints.activate', $c)" size="md" variant="primary" button="Activate now"
                                title="Activate this checkpoint now?"
                                message="The server will set one start time (now) and one deadline ({{ $c->response_window_minutes }} minutes from now) for all {{ $employees->count() }} selected employee(s) at {{ $c->site?->name }}, and notify them immediately." />
                            <div x-data="{ open: false }" class="inline-block">
                                <button type="button" @click="open = true" class="rounded-xs border border-sky-300 px-3 py-1.5 text-sm font-medium text-sky-700 hover:bg-sky-50 dark:border-sky-700 dark:text-sky-300 dark:hover:bg-sky-900/30">{{ $c->isScheduled() ? 'Change start time' : 'Set start time' }}</button>
                                <template x-teleport="body">
                                    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center" @keydown.escape.window="open = false">
                                        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="open = false"></div>
                                        <form method="POST" action="{{ route('checkpoints.schedule', $c) }}" class="relative w-full max-w-md rounded-xs border border-gray-200 bg-white p-5 shadow-2xl dark:border-hair dark:bg-surface">
                                            @csrf
                                            <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">Schedule the checkpoint start</h3>
                                            <p class="mt-1 text-sm text-gray-600 dark:text-slate-300">At this time the server activates the checkpoint for everyone with a {{ $c->response_window_minutes }}-minute window and sends the notifications.</p>
                                            <input type="datetime-local" name="scheduled_start_at" required value="{{ old('scheduled_start_at', $c->scheduled_start_at?->format('Y-m-d\TH:i') ?? now()->addMinutes(30)->format('Y-m-d\TH:i')) }}"
                                                   class="mt-3 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                            <div class="mt-4 flex justify-end gap-2">
                                                <button type="button" @click="open = false" class="rounded-xs border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-slate-600 dark:text-slate-200">Cancel</button>
                                                <button class="rounded-xs bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-700">Schedule</button>
                                            </div>
                                        </form>
                                    </div>
                                </template>
                            </div>
                        @endcan
                        @can('end checkpoint campaign')
                            <x-confirm-action :action="route('checkpoints.cancel', $c)" tone="rose" size="md" button="Cancel" reason title="Cancel this checkpoint?" message="It will not run. It stays in history as cancelled." />
                        @endcan
                    @endif

                    @if($c->status === CheckpointCampaign::ACTIVE)
                        @can('pause checkpoint campaign')
                            <x-confirm-action :action="route('checkpoints.pause', $c)" tone="amber" size="md" button="Pause" reason title="Pause this checkpoint?" message="The countdown freezes for everyone and submissions are put on hold. When you resume, the deadline is extended by the paused time so all employees still get the full window." />
                        @endcan
                    @endif
                    @if($c->status === CheckpointCampaign::PAUSED)
                        @can('pause checkpoint campaign')
                            <x-confirm-action :action="route('checkpoints.resume', $c)" tone="emerald" size="md" button="Resume" title="Resume this checkpoint?" message="The countdown continues with the deadline moved by the paused duration — the same new deadline for everyone." />
                        @endcan
                    @endif
                    @if($live)
                        @can('end checkpoint campaign')
                            <x-confirm-action :action="route('checkpoints.end', $c)" tone="rose" size="md" button="End now" title="Close the window now?" message="Employees without a valid submission are marked missed immediately. Results are kept." />
                            <x-confirm-action :action="route('checkpoints.cancel', $c)" tone="rose" size="md" button="Cancel" reason title="Cancel this checkpoint?" message="Stops the checkpoint without marking anyone as missed. Responses so far are kept." />
                        @endcan
                    @endif
                    @if($c->status === CheckpointCampaign::EXPIRED)
                        @can('end checkpoint campaign')
                            <x-confirm-action :action="route('checkpoints.complete', $c)" tone="brand" size="md" button="Mark completed"
                                title="Mark this checkpoint completed?" message="{{ $counts['open_follow_ups'] ? $counts['open_follow_ups'] . ' employee(s) still have an open follow-up. ' : '' }}You can still review individual cases afterwards." />
                        @endcan
                    @endif
                    @can('export checkpoint reports')
                        @unless($c->isDraft())
                            <a href="{{ route('checkpoints.export', $c) }}" class="rounded-xs border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">Export CSV</a>
                        @endunless
                    @endcan
                </div>
            </div>

            {{-- Official times --}}
            <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                <div class="rounded-xs bg-gray-50 px-3 py-2 dark:bg-slate-800/60"><dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-slate-400">Start time</dt><dd class="font-display text-lg font-bold tabular-nums text-gray-900 dark:text-slate-100">{{ $c->starts_at?->format('g:i A') ?? ($c->scheduled_start_at ? $c->scheduled_start_at->format('M j, g:i A') : '—') }}</dd></div>
                <div class="rounded-xs bg-gray-50 px-3 py-2 dark:bg-slate-800/60"><dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-slate-400">Deadline</dt><dd class="font-display text-lg font-bold tabular-nums text-accent-700 dark:text-accent-300">{{ $c->expires_at?->format('g:i A') ?? '—' }}</dd></div>
                <div class="rounded-xs bg-gray-50 px-3 py-2 dark:bg-slate-800/60"><dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-slate-400">Response window</dt><dd class="font-display text-lg font-bold tabular-nums text-gray-900 dark:text-slate-100">{{ $c->response_window_minutes }} min</dd></div>
                <div class="rounded-xs px-3 py-2 {{ $live ? 'bg-accent-50 dark:bg-accent-900/20' : 'bg-gray-50 dark:bg-slate-800/60' }}"><dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-slate-400">Remaining</dt>
                    <dd class="font-display text-lg font-bold tabular-nums {{ $live ? 'text-accent-700 dark:text-accent-300' : 'text-gray-500' }}">
                        @if($c->status === CheckpointCampaign::PAUSED) Paused @elseif($live) <span x-text="countdown()">{{ gmdate('i:s', $c->secondsRemaining()) }}</span> @elseif($c->expires_at) Closed @else — @endif
                    </dd>
                </div>
                <div class="rounded-xs bg-gray-50 px-3 py-2 dark:bg-slate-800/60"><dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-slate-400">Activated by</dt><dd class="text-sm text-gray-900 dark:text-slate-100">{{ $c->activator?->name ?? ($c->activated_at ? 'Scheduler' : '—') }}<span class="block text-[11px] text-gray-500">{{ $c->activated_at?->format('M j, g:i A') }}</span></dd></div>
                <div class="rounded-xs bg-gray-50 px-3 py-2 dark:bg-slate-800/60"><dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-slate-400">Created by</dt><dd class="text-sm text-gray-900 dark:text-slate-100">{{ $c->creator?->name ?? '—' }}<span class="block text-[11px] text-gray-500">{{ $c->created_at->format('M j, g:i A') }}</span></dd></div>
            </dl>
            @if($c->reason)<p class="mt-3 text-xs text-gray-500 dark:text-slate-400">Reason: “{{ $c->reason }}”</p>@endif
            @if($live)
                <p class="mt-3 text-xs text-gray-500 dark:text-slate-400">Every employee shares this start time and deadline; the server clock is the official time. This page refreshes automatically while the checkpoint is running.</p>
            @endif
        </div>

        @if($c->isDraft())
            {{-- Draft: review employees before activating --}}
            <section class="card p-5">
                <div class="rounded-xs border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm text-sky-800 dark:border-sky-900/50 dark:bg-sky-900/30 dark:text-sky-200">
                    <strong>Review before activating.</strong> Nothing is sent until you activate the checkpoint or its scheduled start time arrives.
                </div>
                <h3 class="mt-4 text-sm font-semibold text-gray-900 dark:text-slate-100">Selected employees <span class="font-normal text-gray-500">({{ $employees->count() }})</span></h3>
                <div class="mt-2 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700">
                        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400"><tr><th class="py-2 pr-4">Employee</th><th class="py-2 pr-4">No.</th><th class="py-2 pr-4">Type</th><th class="py-2">Current assignment</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                            @foreach($employees as $e)
                                <tr>
                                    <td class="py-2 pr-4 font-medium text-gray-900 dark:text-slate-100">{{ $e->full_name }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-slate-400">{{ $e->employee_no }}</td>
                                    <td class="py-2 pr-4 capitalize text-gray-600 dark:text-slate-300">{{ $e->employee_type }}</td>
                                    <td class="py-2 {{ $e->activeAssignment?->site_id === $c->project_site_id ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500 dark:text-slate-400' }}">{{ $e->activeAssignment?->site?->name ?? 'Office / unassigned' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @else
            {{-- Counters --}}
            @php
                $tiles = [
                    ['Included', $counts['total'], 'text-gray-900 dark:text-slate-100'],
                    ['Completed', $counts['completed'], 'text-emerald-600 dark:text-emerald-400'],
                    ['Pending', $counts['pending'], $live ? 'text-sky-600 dark:text-sky-400' : 'text-gray-500'],
                    ['Missed', $counts['missed'], 'text-rose-600 dark:text-rose-400'],
                    ['Outside geofence', $counts['outside'], 'text-rose-600 dark:text-rose-400'],
                    ['Requires review', $counts['review'], 'text-amber-600 dark:text-amber-400'],
                ];
            @endphp
            <div class="grid grid-cols-3 gap-3 lg:grid-cols-6">
                @foreach($tiles as [$label, $value, $tone])
                    <div class="card p-4"><p class="eyebrow text-[10px]">{{ $label }}</p><p class="mt-1.5 text-2xl font-bold tabular-nums {{ $tone }}">{{ $value }}</p></div>
                @endforeach
            </div>

            {{-- TABLE 1 --}}
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Completed employees</h3>
                    <span class="text-xs text-gray-500 dark:text-slate-400">{{ $completed->count() }} of {{ $counts['total'] }}</span>
                </div>
                <x-checkpoints.completed-table :rows="$completed" :campaign="$c" />
            </section>

            {{-- TABLE 2 --}}
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ $live ? 'Pending employees' : 'Pending / non-compliant employees' }}</h3>
                    <span class="text-xs text-gray-500 dark:text-slate-400">{{ $pending->count() }}@if(! $live && $counts['open_follow_ups']) · {{ $counts['open_follow_ups'] }} follow-up(s) open @endif</span>
                </div>
                <x-checkpoints.pending-table :rows="$pending" :campaign="$c" />
            </section>
        @endif

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
                            <span class="w-full text-xs text-gray-500 dark:text-slate-400 sm:w-auto">@foreach($a->details as $k => $v)<span class="mr-2">{{ $k }}: {{ is_scalar($v) ? $v : json_encode($v) }}</span>@endforeach</span>
                        @endif
                    </li>
                @empty
                    <li class="py-4 text-center text-gray-400 dark:text-slate-500">No actions yet.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <script>
        function monitor({ live, status, expiresAt, serverNow, statusUrl }) {
            return {
                live, status, offset: new Date(serverNow).getTime() - Date.now(), expires: expiresAt ? new Date(expiresAt).getTime() : null,
                secondsLeft: 0, lastChange: null,
                init() {
                    if (!this.live) return;
                    this.tick(); setInterval(() => this.tick(), 1000);
                    setInterval(() => this.refresh(), 10000);
                },
                tick() {
                    if (!this.expires) return;
                    this.secondsLeft = Math.max(0, Math.floor((this.expires - (Date.now() + this.offset)) / 1000));
                },
                countdown() { return Math.floor(this.secondsLeft / 60) + ':' + String(this.secondsLeft % 60).padStart(2, '0'); },
                async refresh() {
                    if (document.activeElement && ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) return;
                    try {
                        const r = await fetch(statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                        if (!r.ok) return;
                        const d = await r.json();
                        if (this.lastChange === null) { this.lastChange = d.changed_at; }
                        if (d.status !== this.status || d.changed_at !== this.lastChange) location.reload();
                        if (d.expires_at) this.expires = new Date(d.expires_at).getTime();
                    } catch (e) {}
                },
            };
        }
    </script>
</x-app-layout>
