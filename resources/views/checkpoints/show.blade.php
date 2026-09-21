@php
    use App\Models\CheckpointCampaign;
    $c = $campaign;
    $live = $c->isLive();
    $pct = $counts['total'] ? (int) round($counts['completed'] / $counts['total'] * 100) : 0;
@endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Check Point</h1>
    </x-slot>
    <x-slot name="back">{{ route('checkpoints.index') }}</x-slot>
    <x-slot name="backLabel">Back to Check Point</x-slot>

    <div class="page space-y-5"
         x-data="monitor({ live: @js($live), status: @js($c->status), expiresAt: @js($c->expires_at?->toIso8601String()), serverNow: @js(now()->toIso8601String()), statusUrl: @js(route('checkpoints.status', $c)) })">
        @if($errors->any())
            <div class="rounded-xs border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-900/30 dark:text-rose-200">{{ $errors->first() }}</div>
        @endif

        {{-- What, where, when — and the controls --}}
        <div class="card p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-bold text-gray-900 dark:text-slate-100">{{ $c->name }}</h2>
                        <x-campaign-status-badge :campaign="$c" />
                    </div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-slate-300">{{ $c->site?->name }} · “{{ $c->instruction }}”</p>
                    <p class="mt-2 text-sm tabular-nums text-gray-700 dark:text-slate-200">
                        @if($live)
                            Started <b>{{ $c->starts_at->format('g:i A') }}</b> · deadline <b class="text-accent-700 dark:text-accent-300">{{ $c->expires_at->format('g:i A') }}</b>
                            · @if($c->status === CheckpointCampaign::PAUSED)<span class="badge badge-warn">Paused</span>@else<span class="font-display text-base font-bold text-accent-700 dark:text-accent-300" x-text="countdown()">{{ gmdate('i:s', $c->secondsRemaining()) }}</span> left @endif
                        @elseif($c->starts_at)
                            {{ $c->starts_at->format('D, M j') }} · {{ $c->starts_at->format('g:i A') }} – {{ $c->expires_at?->format('g:i A') }}
                        @elseif($c->isScheduled())
                            Fires at <b class="font-display text-base">{{ $c->scheduled_start_at->format('g:i A') }}</b> on {{ $c->scheduled_start_at->format('D, M j') }}
                            <span class="badge {{ $c->isRandomlyScheduled() ? 'badge-info' : 'badge-neutral' }}">{{ $c->isRandomlyScheduled() ? 'System-generated' : 'Set by admin' }}</span>
                            <span class="text-gray-500 dark:text-slate-400">· {{ $c->response_window_minutes }}-minute window · only admins see this time</span>
                        @else
                            <span class="text-gray-500 dark:text-slate-400">Not scheduled yet · {{ $c->response_window_minutes }}-minute window once it starts</span>
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    @if($c->isDraft())
                        @can('create checkpoint campaign')
                            <a href="{{ route('checkpoints.edit', $c) }}" class="btn-app btn-sm btn-secondary">Edit</a>
                        @endcan
                        @can('activate checkpoint campaign')
                            <x-confirm-action :action="route('checkpoints.activate', $c)" size="md" variant="primary" button="Activate now"
                                title="Activate this checkpoint now?"
                                message="The server will set one start time (now) and one deadline ({{ $c->response_window_minutes }} minutes from now) for all {{ $counts['total'] }} selected employee(s) at {{ $c->site?->name }}, and notify them immediately." />
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
                                title="Mark this checkpoint completed?" message="{{ $counts['not_completed'] ? $counts['not_completed'] . ' employee(s) did not complete it. ' : '' }}The result is kept as it is." />
                        @endcan
                    @endif
                </div>
            </div>
        </div>

        @unless($c->isDraft())
            <div class="stat-strip">
                <x-stat label="Completed" :value="$counts['completed']" :hint="'of '.$counts['total'].' employees'" tone="success" />
                <x-stat label="Waiting" :value="$counts['waiting']" :hint="$live ? 'window still open' : '—'" :tone="$counts['waiting'] ? 'brand' : 'muted'" />
                <x-stat label="Not completed" :value="$counts['not_completed']" :hint="$counts['not_completed'] ? 'missed, outside the site, or failed' : 'none'" :tone="$counts['not_completed'] ? 'danger' : 'muted'" />
                <x-stat label="Completion" :value="$pct.'%'" :hint="$c->expires_at ? 'deadline '.$c->expires_at->format('g:i A') : null" />
            </div>
        @endunless

        {{-- Who completed it --}}
        <section class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">
                    @if($c->isDraft())
                        Employees on this checkpoint <span class="font-normal text-gray-500 dark:text-slate-400">({{ $counts['total'] }})</span>
                    @else
                        <span class="font-display text-lg text-emerald-600 dark:text-emerald-400">{{ $counts['completed'] }}</span>
                        <span class="text-gray-500 dark:text-slate-400">of {{ $counts['total'] }} completed</span>
                        @if($counts['waiting']) · <span class="text-sky-700 dark:text-sky-300">{{ $counts['waiting'] }} waiting</span>@endif
                        @if($counts['not_completed']) · <span class="text-accent-700 dark:text-accent-300">{{ $counts['not_completed'] }} not completed</span>@endif
                    @endif
                </h3>
                @unless($c->isDraft())
                    <div class="flex h-1.5 w-40 overflow-hidden bg-gray-200 dark:bg-slate-700" role="img" aria-label="{{ $pct }}% completed">
                        <div class="bg-emerald-500" style="width: {{ $pct }}%"></div>
                        @if($live)<div class="bg-sky-400" style="width: {{ $counts['total'] ? round($counts['waiting'] / $counts['total'] * 100) : 0 }}%"></div>@endif
                    </div>
                @endunless
            </div>
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700">
                    <thead class="whitespace-nowrap bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-slate-800/60 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Employee</th>
                            <th class="px-4 py-3">No.</th>
                            <th class="px-4 py-3">Result</th>
                            <th class="px-4 py-3">Time</th>
                            @can('view checkpoint results')<th class="px-4 py-3 text-right">Actions</th>@endcan
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($rows as $r)
                            @php $e = $r['employee']; $cp = $r['checkpoint']; @endphp
                            <tr class="hover:bg-gray-50/60 dark:hover:bg-slate-800/40">
                                <td class="cell-head px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{{ $e->full_name }}</td>
                                <td data-label="No." class="px-4 py-3 text-gray-500 dark:text-slate-400">{{ $e->employee_no }}</td>
                                <td data-label="Result" class="px-4 py-3">
                                    @switch($r['result'])
                                        @case('completed') <span class="badge badge-success"><i class="dot"></i>Completed</span> @break
                                        @case('waiting') <span class="badge badge-info"><i class="dot animate-pulse"></i>Waiting</span> @break
                                        @case('not_completed') <span class="badge badge-danger"><i class="dot"></i>Not completed</span> @break
                                        @default <span class="badge badge-neutral">Not started</span>
                                    @endswitch
                                </td>
                                <td data-label="Time" class="px-4 py-3 whitespace-nowrap tabular-nums text-gray-700 dark:text-slate-200">
                                    {{ $cp?->submitted_at?->format('g:i A') ?? '—' }}
                                </td>
                                @can('view checkpoint results')
                                    <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                                        @if($cp)
                                            <div class="row-actions"><a href="{{ route('checkpoints.results.show', $cp) }}">Details</a></div>
                                        @endif
                                    </td>
                                @endcan
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400 dark:text-slate-500">No employees on this checkpoint.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
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
