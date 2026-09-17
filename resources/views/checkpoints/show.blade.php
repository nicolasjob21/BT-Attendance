@php
    use App\Models\CheckpointCampaign;
    $c = $campaign;
    $live = $c->isLive();
@endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Check Point · {{ $c->isDraft() ? 'Review' : 'Monitoring' }}</h1>
    </x-slot>
    <x-slot name="back">{{ route('checkpoints.index') }}</x-slot>
    <x-slot name="backLabel">Back to Check Point</x-slot>

    <div class="page space-y-5"
         x-data="monitor({ live: @js($live), status: @js($c->status), expiresAt: @js($c->expires_at?->toIso8601String()), serverNow: @js(now()->toIso8601String()), statusUrl: @js(route('checkpoints.status', $c)) })">
        @if($errors->any())
            <div class="rounded-xs border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-900/30 dark:text-rose-200">{{ $errors->first() }}</div>
        @endif

        {{-- Header: shared checkpoint info + controls --}}
        <div class="card p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    @if($c->starts_at)<a href="{{ route('checkpoints.daily', ['date' => $c->starts_at->toDateString(), 'site' => $c->project_site_id]) }}" class="text-xs text-brand-700 hover:underline dark:text-brand-300">All checkpoints on {{ $c->starts_at->format('M j') }} at this site</a>@endif
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-bold text-gray-900 dark:text-slate-100">{{ $c->name }}</h2>
                        <x-campaign-status-badge :campaign="$c" />
                    </div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-slate-300">{{ $c->site?->name }} · <span class="font-medium text-gray-800 dark:text-slate-100">“{{ $c->instruction }}”</span></p>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    @if($c->isDraft())
                        @can('create checkpoint campaign')
                            <a href="{{ route('checkpoints.edit', $c) }}" class="btn-app btn-sm btn-secondary">Edit</a>
                        @endcan
                        @can('activate checkpoint campaign')
                            <x-confirm-action :action="route('checkpoints.activate', $c)" size="md" variant="primary" button="Activate now"
                                title="Activate this checkpoint now?"
                                message="The server will set one start time (now) and one deadline ({{ $c->response_window_minutes }} minutes from now) for all {{ $employees->count() }} selected employee(s) at {{ $c->site?->name }}, and notify them immediately." />
                            <div x-data="{ open: false, mode: 'random' }" class="inline-block">
                                <button type="button" @click="open = true" class="btn-app btn-sm btn-outline-brand">{{ $c->isScheduled() ? 'Change start time' : 'Schedule' }}</button>
                                <template x-teleport="body">
                                    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center" @keydown.escape.window="open = false">
                                        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="open = false"></div>
                                        <div x-show="open" x-transition class="relative w-full max-w-md rounded-xs border border-gray-200 bg-white p-5 shadow-2xl dark:border-hair dark:bg-surface">
                                            <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">Schedule the checkpoint</h3>

                                            {{-- Mode switch --}}
                                            <div class="mt-3 grid grid-cols-2 gap-1 rounded-xs border border-gray-200 p-1 dark:border-slate-700">
                                                <button type="button" @click="mode = 'random'" :class="mode === 'random' ? 'bg-brand-500 text-white' : 'text-gray-600 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-700/60'" class="rounded-xs px-3 py-1.5 text-xs font-semibold uppercase tracking-wider transition">Let the system pick</button>
                                                <button type="button" @click="mode = 'manual'" :class="mode === 'manual' ? 'bg-brand-500 text-white' : 'text-gray-600 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-700/60'" class="rounded-xs px-3 py-1.5 text-xs font-semibold uppercase tracking-wider transition">Exact time</button>
                                            </div>

                                            {{-- Random within a window --}}
                                            <form x-show="mode === 'random'" method="POST" action="{{ route('checkpoints.schedule-random', $c) }}" class="mt-4">
                                                @csrf
                                                <p class="text-sm text-gray-600 dark:text-slate-300">Pick the day and the working hours. The server draws a random start time inside that window (leaving room for the {{ $c->response_window_minutes }}-minute response window). <b class="text-gray-900 dark:text-slate-100">You will see the drawn time</b> on this page so you can give the team leader a heads-up — employees will not.</p>
                                                <label class="mt-3 block text-xs font-medium text-gray-700 dark:text-slate-200">Date</label>
                                                <input type="date" name="date" required value="{{ old('date', ($c->scheduled_start_at ?? now())->toDateString()) }}" min="{{ now()->toDateString() }}"
                                                       class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                                <div class="mt-3 grid grid-cols-2 gap-3">
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-700 dark:text-slate-200">Between</label>
                                                        <input type="time" name="window_start" required value="{{ old('window_start', $c->random_window_start ? substr($c->random_window_start, 0, 5) : '08:30') }}"
                                                               class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-700 dark:text-slate-200">And</label>
                                                        <input type="time" name="window_end" required value="{{ old('window_end', $c->random_window_end ? substr($c->random_window_end, 0, 5) : '17:30') }}"
                                                               class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                                    </div>
                                                </div>
                                                @error('random_window') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                                                <div class="mt-4 flex justify-end gap-2">
                                                    <button type="button" @click="open = false" class="btn-app btn-md btn-secondary">Cancel</button>
                                                    <button class="btn-app btn-md btn-brand">{{ $c->isRandomlyScheduled() ? 'Draw a new time' : 'Generate time' }}</button>
                                                </div>
                                            </form>

                                            {{-- Exact time --}}
                                            <form x-show="mode === 'manual'" x-cloak method="POST" action="{{ route('checkpoints.schedule', $c) }}" class="mt-4">
                                                @csrf
                                                <p class="text-sm text-gray-600 dark:text-slate-300">At this time the server activates the checkpoint for everyone with a {{ $c->response_window_minutes }}-minute window.</p>
                                                <input type="datetime-local" name="scheduled_start_at" required value="{{ old('scheduled_start_at', $c->scheduled_start_at?->format('Y-m-d\TH:i') ?? now()->addMinutes(30)->format('Y-m-d\TH:i')) }}"
                                                       class="mt-3 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                                @error('scheduled_start_at') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                                                <div class="mt-4 flex justify-end gap-2">
                                                    <button type="button" @click="open = false" class="btn-app btn-md btn-secondary">Cancel</button>
                                                    <button class="btn-app btn-md btn-brand">Schedule</button>
                                                </div>
                                            </form>
                                        </div>
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
                            <a href="{{ route('checkpoints.export', $c) }}" class="btn-app btn-sm btn-secondary">Export CSV</a>
                        @endunless
                    @endcan
                </div>
            </div>

            @if($c->isScheduled())
                <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xs border border-brand-400/40 bg-brand-400/10 px-4 py-3 dark:bg-brand-500/10">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">Checkpoint fires at</p>
                        <p class="font-display text-2xl font-bold tabular-nums text-gray-900 dark:text-slate-100">{{ $c->scheduled_start_at->format('g:i A') }} <span class="text-sm font-medium text-gray-500 dark:text-slate-400">{{ $c->scheduled_start_at->format('D, M j') }}</span></p>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-slate-300">
                        @if($c->isRandomlyScheduled())
                            <span class="badge badge-info">System-generated</span> drawn from {{ $c->randomWindowLabel() }}.
                        @else
                            <span class="badge badge-neutral">Set by admin</span>
                        @endif
                        Only admins see this — give the PM / team leader a heads-up before then.
                    </p>
                </div>
            @endif

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
            {{-- Outcome: completed vs not completed (waiting only while the window is open) --}}
            @php
                $notCompleted = $counts['total'] - $counts['completed'] - ($live ? $counts['pending'] : 0);
                $pct = $counts['total'] ? (int) round($counts['completed'] / $counts['total'] * 100) : 0;
            @endphp
            <div class="card p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <p class="eyebrow text-[10px]">Completed</p>
                        <p class="mt-1 font-display text-3xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $counts['completed'] }} <span class="text-base font-medium text-gray-500 dark:text-slate-400">of {{ $counts['total'] }}</span></p>
                    </div>
                    <div>
                        <p class="eyebrow text-[10px]">Not completed</p>
                        <p class="mt-1 font-display text-3xl font-bold tabular-nums {{ $notCompleted ? 'text-accent-600 dark:text-accent-400' : 'text-gray-400 dark:text-slate-500' }}">{{ $notCompleted }}</p>
                    </div>
                    @if($live)
                        <div>
                            <p class="eyebrow text-[10px]">Waiting</p>
                            <p class="mt-1 font-display text-3xl font-bold tabular-nums text-sky-600 dark:text-sky-400">{{ $counts['pending'] }}</p>
                        </div>
                    @else
                        <div>
                            <p class="eyebrow text-[10px]">Completion rate</p>
                            <p class="mt-1 font-display text-3xl font-bold tabular-nums text-gray-900 dark:text-slate-100">{{ $pct }}%</p>
                        </div>
                    @endif
                </div>
                <div class="mt-4 flex h-2 overflow-hidden rounded-none bg-gray-200 dark:bg-slate-700" role="img" aria-label="{{ $counts['completed'] }} completed, {{ $notCompleted }} not completed">
                    <div class="bg-emerald-500" style="width: {{ $pct }}%"></div>
                    @if($live)<div class="bg-sky-400" style="width: {{ $counts['total'] ? round($counts['pending'] / $counts['total'] * 100) : 0 }}%"></div>@endif
                    <div class="bg-accent-500" style="width: {{ $counts['total'] ? round($notCompleted / $counts['total'] * 100) : 0 }}%"></div>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-slate-400">
                    Not completed breaks down as: <b class="text-gray-800 dark:text-slate-200">{{ $counts['missed'] }}</b> missed ·
                    <b class="text-gray-800 dark:text-slate-200">{{ $counts['outside'] }}</b> outside geofence ·
                    <b class="text-gray-800 dark:text-slate-200">{{ $counts['review'] }}</b> requires review
                    @if(! $live && $counts['open_follow_ups']) · <b class="text-amber-700 dark:text-amber-300">{{ $counts['open_follow_ups'] }}</b> follow-up(s) open @endif
                </p>
            </div>

            {{-- TABLE 1 --}}
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100"><span class="badge badge-success mr-2">Completed</span>Employees who completed the checkpoint</h3>
                    <span class="text-xs text-gray-500 dark:text-slate-400">{{ $completed->count() }} of {{ $counts['total'] }}</span>
                </div>
                <x-checkpoints.completed-table :rows="$completed" :campaign="$c" />
            </section>

            {{-- TABLE 2 --}}
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">@if($live)<span class="badge badge-info mr-2">Waiting</span>Employees who have not responded yet @else<span class="badge badge-danger mr-2">Not completed</span>Employees who did not complete the checkpoint @endif</h3>
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
