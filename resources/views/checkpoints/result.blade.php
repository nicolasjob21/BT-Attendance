@php
    use App\Models\Checkpoint;
    $cp = $checkpoint;
    $c = $cp->campaign;
    $site = $cp->site;
    $secs = $cp->responseSeconds();
@endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Checkpoint {{ $cp->reference() }}</h1>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-5">
        @if(session('status'))
            <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-xs border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-900/30 dark:text-rose-200">{{ $errors->first() }}</div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <a href="{{ route('checkpoints.show', $c) }}" class="text-xs text-brand-700 hover:underline dark:text-brand-300">← {{ $c->name }}</a>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-slate-100">{{ $cp->employee?->full_name }}</h2>
                    <x-checkpoint-status-badge :checkpoint="$cp" />
                    @if($cp->escalated_at)<span class="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">Escalated {{ $cp->escalated_at->format('M j') }}</span>@endif
                </div>
                <p class="text-sm text-gray-600 dark:text-slate-300">{{ $cp->employee?->employee_no }} · {{ $site?->name }} · window {{ $c->starts_at?->format('M j, g:i A') }} – {{ $c->expires_at?->format('g:i A') }}</p>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            {{-- Photo --}}
            <section class="card overflow-hidden">
                <div class="border-b border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-900 dark:border-slate-700 dark:text-slate-100">Photo</div>
                @if($hasPhoto)
                    <button type="button" @click="$dispatch('open-lightbox', '{{ route('checkpoints.photo', $cp) }}')" class="block w-full bg-black">
                        <img src="{{ route('checkpoints.photo', $cp) }}" alt="Checkpoint photo" class="mx-auto max-h-80 w-full object-contain">
                    </button>
                    <p class="px-4 py-2 text-xs text-gray-500 dark:text-slate-400">Instruction: “{{ $c->instruction }}” · live camera capture, stamped on the server.</p>
                @else
                    <div class="grid h-56 place-items-center text-sm text-gray-400 dark:text-slate-500">No photo submitted</div>
                @endif
            </section>

            {{-- Map --}}
            <section class="card overflow-hidden">
                <div class="border-b border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-900 dark:border-slate-700 dark:text-slate-100">Map</div>
                <div x-data="checkpointMap({
                        site: @js($site ? ['lat' => (float) $site->latitude, 'lng' => (float) $site->longitude, 'r' => (int) $site->geofence_radius_m, 'name' => $site->name] : null),
                        fix: @js($cp->latitude !== null ? ['lat' => (float) $cp->latitude, 'lng' => (float) $cp->longitude, 'acc' => (float) ($cp->gps_accuracy_meters ?? 0)] : null),
                     })" x-init="init()">
                    <div x-ref="map" class="relative z-0 h-64 w-full bg-gray-100 dark:bg-deep"></div>
                </div>
                <div class="px-4 py-2 text-xs text-gray-600 dark:text-slate-300">
                    <x-checkpoints.gps-badge :checkpoint="$cp" />
                    @if($cp->distance_from_site_meters !== null)<span class="ml-2">{{ number_format((float) $cp->distance_from_site_meters) }} m from {{ $site?->name }} (fence {{ $site?->geofence_radius_m }} m)</span>@endif
                    @if($cp->matchedSite && $cp->matched_site_id !== $cp->project_site_id)<span class="block">Inside authorized location: {{ $cp->matchedSite->name }}</span>@endif
                </div>
            </section>

            {{-- Details --}}
            <section class="card p-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Details</h3>
                <dl class="mt-2 space-y-1.5 text-sm">
                    @foreach([
                        'Checkpoint start' => $c->starts_at?->format('M j, g:i:s A') ?? '—',
                        'Deadline' => $c->expires_at?->format('g:i:s A') ?? '—',
                        'Notification' => $cp->notification_status,
                        'Attempts' => $cp->submission_attempts . ($cp->last_attempt_at ? ' · last ' . $cp->last_attempt_at->format('g:i:s A') : ''),
                        'Submitted (server)' => $cp->submitted_at?->format('g:i:s A') ?? '—',
                        'Response time' => $secs !== null ? intdiv($secs, 60) . 'm ' . ($secs % 60) . 's' : '—',
                        'Device clock' => $cp->client_timestamp?->format('g:i:s A') ?? '—',
                        'Coordinates' => $cp->latitude !== null ? $cp->latitude . ', ' . $cp->longitude : '—',
                        'GPS accuracy' => $cp->gps_accuracy_meters !== null ? '±' . number_format((float) $cp->gps_accuracy_meters) . ' m' : '—',
                        'Network' => $cp->network_status ? ucfirst(str_replace('_', ' ', $cp->network_status)) : '—',
                        'Issue reported' => $cp->issue_reported ? (Checkpoint::ISSUES[$cp->issue_reported] ?? $cp->issue_reported) : '—',
                        'Project assignment' => $cp->employee?->activeAssignment?->site?->name ?? 'Office / unassigned',
                    ] as $k => $v)
                        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-slate-400">{{ $k }}</dt><dd class="text-right text-gray-900 tabular-nums dark:text-slate-100">{{ $v }}</dd></div>
                    @endforeach
                </dl>
                @if($cp->validation_message)<p class="mt-3 rounded-xs bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:bg-slate-800/60 dark:text-slate-200">{{ $cp->validation_message }}</p>@endif
                <p class="mt-2 text-[11px] text-gray-400 dark:text-slate-500">Start, deadline and server timestamps are fixed and cannot be edited.</p>
            </section>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            {{-- Movement context --}}
            <section class="card p-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Movement records that day</h3>
                <p class="text-xs text-gray-500 dark:text-slate-400">Approved leave and punches on {{ ($c->starts_at ?? $cp->created_at)->format('D, M j') }}.</p>
                <div class="mt-3 space-y-2 text-sm">
                    @if($context['approved_leave'])
                        @php $l = $context['approved_leave']; @endphp
                        <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-900/20 dark:text-emerald-100">
                            <p class="font-medium">Approved {{ $l->is_early_leave ? 'early leave' : ($l->leaveType?->name ?? 'leave') }} covers this checkpoint</p>
                            <p class="text-xs opacity-80">{{ \App\Models\LeaveRequest::PORTION_LABELS[$l->day_portion] ?? '' }}@if($l->is_early_leave && $l->requested_time_out) · out by {{ \Carbon\Carbon::parse($l->requested_time_out)->format('g:i A') }}@endif @if($l->reason) · “{{ $l->reason }}”@endif</p>
                        </div>
                    @elseif($cp->isNonCompliant())
                        <div class="rounded-xs border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900 dark:border-amber-900/50 dark:bg-amber-900/20 dark:text-amber-100">
                            <p class="font-medium">No approved leave record for this time</p>
                            <p class="text-xs opacity-80">Follow up with the employee — this is not automatically misconduct.</p>
                        </div>
                    @endif
                    @if($context['clocked_out'])<p class="text-xs text-gray-600 dark:text-slate-300">Employee had clocked <strong>out</strong> at {{ $context['last_punch']->logged_at->format('g:i A') }} before this checkpoint.</p>@endif
                    <ul class="divide-y divide-gray-100 rounded-xs border border-gray-200 dark:divide-slate-700 dark:border-slate-700">
                        @forelse($context['punches'] as $p)
                            <li class="flex items-center justify-between px-3 py-1.5 text-xs">
                                <span class="font-medium text-gray-800 dark:text-slate-100">{{ $p->log_type === 'time_in' ? 'Time in' : 'Time out' }} · {{ $p->logged_at->format('g:i A') }}</span>
                                <span class="text-gray-500 dark:text-slate-400">{{ $p->site?->name ?? 'no site' }}</span>
                                <x-location-badge :status="$p->location_status" :verification="$p->location_verification_status" compact />
                            </li>
                        @empty
                            <li class="px-3 py-3 text-center text-xs text-gray-400 dark:text-slate-500">No punches recorded that day.</li>
                        @endforelse
                    </ul>
                </div>

                @if($cp->employee_explanation)
                    <div class="mt-4">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Employee's explanation</h4>
                        <p class="mt-1 rounded-xs bg-gray-50 px-3 py-2 text-sm text-gray-800 dark:bg-slate-800/60 dark:text-slate-100">“{{ $cp->employee_explanation }}”</p>
                    </div>
                @endif

                {{-- Review records --}}
                <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Review records</h4>
                <ul class="mt-1 divide-y divide-gray-100 text-sm dark:divide-slate-700">
                    @forelse($cp->reviews as $r)
                        <li class="py-2">
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <span class="font-medium text-gray-900 dark:text-slate-100">{{ $r->action_label }}</span>
                                @if($r->reason)<span class="text-xs text-gray-600 dark:text-slate-300">{{ Checkpoint::HR_REASONS[$r->reason] ?? $r->reason }}</span>@endif
                                <span class="text-xs text-gray-400 dark:text-slate-500">{{ $r->reviewer?->name }} · {{ $r->created_at->format('M j, g:i A') }}</span>
                            </div>
                            @if($r->explanation)<p class="text-xs text-gray-700 dark:text-slate-200">Employee: “{{ $r->explanation }}”</p>@endif
                            @if($r->note)<p class="text-xs text-gray-500 dark:text-slate-400">Note: {{ $r->note }}</p>@endif
                        </li>
                    @empty
                        <li class="py-2 text-xs text-gray-400 dark:text-slate-500">No review records yet.</li>
                    @endforelse
                </ul>
            </section>

            {{-- HR follow-up --}}
            <section class="card p-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">HR follow-up</h3>
                @if(! $cp->isReviewable())
                    <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">Completed within the window — nothing to follow up.</p>
                @else
                    @if($cp->reviewed_at)
                        <div class="mt-2 rounded-xs border border-gray-200 px-3 py-2 text-sm dark:border-slate-700">
                            <p class="font-medium text-gray-900 dark:text-slate-100">{{ $cp->status === Checkpoint::APPROVED_EXCEPTION ? 'Exception approved' : 'Exception rejected' }} — {{ $cp->hr_reason_label }}</p>
                            @if($cp->hr_note)<p class="text-gray-700 dark:text-slate-200">“{{ $cp->hr_note }}”</p>@endif
                            <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">— {{ $cp->reviewer?->name }}, {{ $cp->reviewed_at->format('M j, g:i A') }}</p>
                        </div>
                    @endif
                    @can('review checkpoint exceptions')
                        <div x-data="{ tab: '{{ $cp->reviewed_at ? 'note' : 'explanation' }}' }" class="mt-3">
                            <div class="flex flex-wrap gap-1 text-xs">
                                @foreach(['explanation' => 'Record explanation', 'decide' => 'Approve / reject', 'note' => 'Add HR note', 'escalate' => 'Escalate'] as $t => $l)
                                    <button type="button" @click="tab = '{{ $t }}'" :class="tab === '{{ $t }}' ? 'bg-brand-600 text-white' : 'border border-gray-300 text-gray-700 dark:border-slate-600 dark:text-slate-200'" class="rounded-xs px-2.5 py-1 font-medium">{{ $l }}</button>
                                @endforeach
                            </div>

                            <form x-show="tab === 'explanation'" method="POST" action="{{ route('checkpoints.results.follow-up', $cp) }}" class="mt-3 space-y-2">
                                @csrf <input type="hidden" name="action" value="explanation">
                                <label class="block text-xs font-medium text-gray-600 dark:text-slate-300">Employee's explanation (as told to HR)</label>
                                <textarea name="explanation" rows="3" required maxlength="1000" class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">{{ old('explanation', $cp->employee_explanation) }}</textarea>
                                <label class="block text-xs font-medium text-gray-600 dark:text-slate-300">Reason</label>
                                <select name="reason" class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                    <option value="">Select a reason…</option>
                                    @foreach(Checkpoint::HR_REASONS as $v => $l)<option value="{{ $v }}" @selected(old('reason', $cp->hr_reason) === $v)>{{ $l }}</option>@endforeach
                                </select>
                                <button class="rounded-xs bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700">Record explanation</button>
                            </form>

                            <form x-show="tab === 'decide'" x-cloak method="POST" action="{{ route('checkpoints.results.follow-up', $cp) }}" class="mt-3 space-y-2">
                                @csrf
                                <label class="block text-xs font-medium text-gray-600 dark:text-slate-300">Reason</label>
                                <select name="reason" required class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                    <option value="">Select a reason…</option>
                                    @foreach(Checkpoint::HR_REASONS as $v => $l)<option value="{{ $v }}" @selected(old('reason', $cp->hr_reason) === $v)>{{ $l }}</option>@endforeach
                                </select>
                                <label class="block text-xs font-medium text-gray-600 dark:text-slate-300">Note (optional)</label>
                                <textarea name="note" rows="2" maxlength="1000" class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600"></textarea>
                                <div class="flex flex-wrap gap-2">
                                    <button name="action" value="approve" class="rounded-xs bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Approve exception</button>
                                    <button name="action" value="reject" class="rounded-xs border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-300 dark:hover:bg-rose-900/30">Reject exception</button>
                                    @if($cp->status !== Checkpoint::PENDING_REVIEW)
                                        <button name="action" value="mark_review" class="rounded-xs border border-amber-300 px-4 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300">Mark as pending review</button>
                                    @endif
                                </div>
                                <p class="text-[11px] text-gray-500 dark:text-slate-400">Approving counts the checkpoint as <em>completed after review</em>. The decision is stored as a separate review record; the original evidence is unchanged.</p>
                            </form>

                            <form x-show="tab === 'note'" x-cloak method="POST" action="{{ route('checkpoints.results.follow-up', $cp) }}" class="mt-3 space-y-2">
                                @csrf <input type="hidden" name="action" value="note">
                                <textarea name="note" rows="3" required maxlength="1000" placeholder="Internal HR note…" class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600"></textarea>
                                <button class="rounded-xs bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700">Add note</button>
                            </form>

                            <form x-show="tab === 'escalate'" x-cloak method="POST" action="{{ route('checkpoints.results.follow-up', $cp) }}" class="mt-3 space-y-2">
                                @csrf <input type="hidden" name="action" value="escalate">
                                <textarea name="note" rows="3" maxlength="1000" placeholder="Why this case is being escalated to management…" class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600"></textarea>
                                <button class="rounded-xs bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">Escalate to management</button>
                            </form>
                        </div>
                    @endcan
                    <div class="mt-4 flex flex-wrap gap-2 text-xs">
                        @if($cp->employee?->email)<a href="mailto:{{ $cp->employee->email }}?subject={{ rawurlencode('Checkpoint ' . $cp->reference()) }}" class="rounded-xs border border-gray-300 px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200">Contact employee</a>@endif
                        @can('manage employees')<a href="{{ route('employees.edit', $cp->employee_id) }}" class="rounded-xs border border-gray-300 px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200">Employee details</a>@endcan
                        @can('view team reports')<a href="{{ route('attendance.timesheet', $cp->employee_id) }}" class="rounded-xs border border-gray-300 px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200">Timesheet</a>@endcan
                    </div>
                @endif
            </section>
        </div>

        <section class="card p-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Audit trail</h3>
            <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-slate-700">
                @forelse($audit as $a)
                    <li class="flex flex-wrap items-baseline gap-x-3 py-1.5">
                        <span class="w-36 shrink-0 text-xs tabular-nums text-gray-500 dark:text-slate-400">{{ $a->created_at->format('M j, g:i:s A') }}</span>
                        <span class="font-medium text-gray-900 dark:text-slate-100">{{ $a->action_label }}</span>
                        <span class="text-xs text-gray-500 dark:text-slate-400">{{ $a->user?->name ?? 'System' }}</span>
                        @if($a->details)<span class="text-xs text-gray-500 dark:text-slate-400">@foreach($a->details as $k => $v)<span class="mr-2">{{ $k }}: {{ is_scalar($v) ? $v : json_encode($v) }}</span>@endforeach</span>@endif
                    </li>
                @empty
                    <li class="py-3 text-center text-gray-400 dark:text-slate-500">No actions recorded.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <script>
        function checkpointMap({ site, fix }) {
            return {
                init() {
                    const map = L.map(this.$refs.map, { zoomControl: true, attributionControl: false });
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
                    const layers = [];
                    if (site) {
                        layers.push(L.circle([site.lat, site.lng], { radius: site.r, color: '#4a9bb5', weight: 1.5, fillColor: '#7ec8e3', fillOpacity: 0.2 }).addTo(map));
                        L.marker([site.lat, site.lng]).addTo(map).bindTooltip(site.name, { direction: 'top' });
                    }
                    if (fix) {
                        layers.push(L.circleMarker([fix.lat, fix.lng], { radius: 7, color: '#fff', weight: 2, fillColor: '#ea6c44', fillOpacity: 1 }).addTo(map).bindTooltip('Employee fix'));
                        if (fix.acc) layers.push(L.circle([fix.lat, fix.lng], { radius: fix.acc, color: '#ea6c44', weight: 1, fillColor: '#f7a88a', fillOpacity: 0.15 }).addTo(map));
                    }
                    if (layers.length) map.fitBounds(L.featureGroup(layers).getBounds().pad(0.3)); else map.setView([14.6108, 121.0049], 13);
                    setTimeout(() => map.invalidateSize(), 200);
                },
            };
        }
    </script>
</x-app-layout>
