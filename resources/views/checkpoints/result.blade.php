@php
    use App\Models\Checkpoint;
    $cp = $checkpoint;
    $site = $cp->site;
    $gps = $cp->latitude === null ? 'gps_unavailable' : ($cp->failure_reason === 'low_gps_accuracy' ? 'low_accuracy' : ($cp->within_geofence ? 'verified_location' : ($cp->matched_site_id ? 'authorized_alternate_location' : 'outside_authorized_area')));
@endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Checkpoint {{ $cp->reference() }}</h1>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-5">
        @if(session('status'))
            <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200">{{ session('status') }}</div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('checkpoints.results.index') }}" class="text-xs text-brand-700 hover:underline dark:text-brand-300">← Back</a>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-slate-100">{{ $cp->employee?->full_name }}</h2>
                    <x-checkpoint-status-badge :status="$cp->verification_status" :review="$cp->review_status" />
                </div>
                <p class="text-sm text-gray-600 dark:text-slate-300">
                    {{ $cp->employee?->employee_no }} · {{ $site?->name }} ·
                    <a href="{{ route('checkpoints.show', $cp->campaign_id) }}" class="hover:underline">{{ $cp->campaign?->name }}</a>
                </p>
            </div>
            @if($cp->needsReview())
                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Exception — needs review</span>
            @endif
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            {{-- Photo --}}
            <section class="card overflow-hidden">
                <div class="border-b border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-900 dark:border-slate-700 dark:text-slate-100">Photo</div>
                @if($hasPhoto)
                    <button type="button" @click="$dispatch('open-lightbox', '{{ route('checkpoints.photo', $cp) }}')" class="block w-full bg-black">
                        <img src="{{ route('checkpoints.photo', $cp) }}" alt="Checkpoint photo" class="mx-auto max-h-80 w-full object-contain">
                    </button>
                    <p class="px-4 py-2 text-xs text-gray-500 dark:text-slate-400">Instruction: “{{ $cp->photo_instruction }}” · live camera capture, stamped on the server.</p>
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
                    <x-location-badge :status="$gps" />
                    @if($cp->distance_from_site_meters !== null)
                        <span class="ml-2">{{ number_format((float) $cp->distance_from_site_meters) }} m from {{ $site?->name }} (fence {{ $site?->geofence_radius_m }} m)</span>
                    @endif
                    @if($cp->matchedSite && $cp->matched_site_id !== $cp->project_site_id)
                        <span class="block">Inside authorized location: {{ $cp->matchedSite->name }}</span>
                    @endif
                </div>
            </section>

            {{-- Details --}}
            <section class="card p-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Details</h3>
                <dl class="mt-2 space-y-1.5 text-sm">
                    @foreach([
                        'Opened' => $cp->opened_at?->format('M j, Y g:i:s A') ?? '—',
                        'Expires' => $cp->expires_at?->format('g:i:s A') ?? '—',
                        'Submitted (server)' => $cp->submitted_at?->format('g:i:s A') ?? '—',
                        'Device clock' => $cp->client_timestamp?->format('g:i:s A') ?? '—',
                        'Coordinates' => $cp->latitude !== null ? $cp->latitude . ', ' . $cp->longitude : '—',
                        'GPS accuracy' => $cp->gps_accuracy_meters !== null ? '±' . number_format((float) $cp->gps_accuracy_meters) . ' m' : '—',
                        'Network' => $cp->network_status ? ucfirst(str_replace('_', ' ', $cp->network_status)) : '—',
                        'Result' => $cp->result_label ?? '—',
                    ] as $k => $v)
                        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-slate-400">{{ $k }}</dt><dd class="text-right text-gray-900 tabular-nums dark:text-slate-100">{{ $v }}</dd></div>
                    @endforeach
                </dl>
                @if($cp->validation_message)
                    <p class="mt-3 rounded-xs bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:bg-slate-800/60 dark:text-slate-200">{{ $cp->validation_message }}</p>
                @endif
            </section>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            {{-- Movement context (Leave / Return site integration) --}}
            <section class="card p-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Movement records that day</h3>
                <p class="text-xs text-gray-500 dark:text-slate-400">Approved leave and punches on {{ $cp->scheduled_for->format('D, M j') }} — so an absence that was already approved is not treated as a violation.</p>

                <div class="mt-3 space-y-2 text-sm">
                    @if($context['approved_leave'])
                        @php $l = $context['approved_leave']; @endphp
                        <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-900/20 dark:text-emerald-100">
                            <p class="font-medium">Approved {{ $l->is_early_leave ? 'early leave' : ($l->leaveType?->name ?? 'leave') }} covers this checkpoint</p>
                            <p class="text-xs opacity-80">
                                {{ \App\Models\LeaveRequest::PORTION_LABELS[$l->day_portion] ?? '' }}
                                @if($l->is_early_leave && $l->requested_time_out) · out by {{ \Carbon\Carbon::parse($l->requested_time_out)->format('g:i A') }}@endif
                                @if($l->reason) · “{{ $l->reason }}”@endif
                            </p>
                        </div>
                    @elseif($cp->isException())
                        <div class="rounded-xs border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900 dark:border-amber-900/50 dark:bg-amber-900/20 dark:text-amber-100">
                            <p class="font-medium">No approved leave-site record for this time</p>
                            <p class="text-xs opacity-80">Flagged for review — not automatically treated as misconduct.</p>
                        </div>
                    @endif

                    @if($context['clocked_out'])
                        <p class="text-xs text-gray-600 dark:text-slate-300">Employee had clocked <strong>out</strong> at {{ $context['last_punch']->logged_at->format('g:i A') }} before this checkpoint.</p>
                    @endif

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
            </section>

            {{-- Review --}}
            <section class="card p-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Exception review</h3>
                @if($cp->review_status === 'reviewed')
                    <div class="mt-2 rounded-xs border border-gray-200 px-3 py-2 text-sm dark:border-slate-700">
                        <p class="font-medium text-gray-900 dark:text-slate-100">{{ $cp->review_result_label }}</p>
                        @if($cp->review_remarks)<p class="text-gray-700 dark:text-slate-200">“{{ $cp->review_remarks }}”</p>@endif
                        <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">— {{ $cp->reviewer?->name }}, {{ $cp->reviewed_at?->format('M j, g:i A') }}</p>
                    </div>
                @elseif(! $cp->isException())
                    <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">Nothing to review — this checkpoint was {{ strtolower($cp->status_label) }}.</p>
                @endif

                @can('review checkpoint exceptions')
                    @if($cp->isException())
                        <div x-data="{ change: {{ $cp->review_status === 'reviewed' ? 'false' : 'true' }} }" class="mt-3">
                            <button x-show="!change" type="button" @click="change = true" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">Change decision</button>
                            <form x-show="change" method="POST" action="{{ route('checkpoints.results.review', $cp) }}" class="space-y-3">
                                @csrf
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-300">Classify the result</label>
                                    <select name="review_result" required class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                        <option value="">Select…</option>
                                        @foreach(Checkpoint::REVIEW_RESULTS as $v => $l)
                                            <option value="{{ $v }}" @selected(old('review_result', $cp->review_result) === $v)>{{ $l }}</option>
                                        @endforeach
                                    </select>
                                    @error('review_result') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-300">Review remarks</label>
                                    <textarea name="review_remarks" rows="3" maxlength="1000" class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">{{ old('review_remarks', $cp->review_remarks) }}</textarea>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button class="rounded-xs bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Mark as reviewed</button>
                                    <button formaction="{{ route('checkpoints.results.remarks', $cp) }}" class="rounded-xs border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">Save remarks only</button>
                                </div>
                                <p class="text-[11px] text-gray-500 dark:text-slate-400">Reviewing records a decision only. No salary deduction or disciplinary action is applied automatically.</p>
                            </form>
                        </div>
                    @endif
                @endcan
            </section>
        </div>

        {{-- Audit --}}
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
                        const pin = L.circleMarker([fix.lat, fix.lng], { radius: 7, color: '#fff', weight: 2, fillColor: '#ea6c44', fillOpacity: 1 }).addTo(map).bindTooltip('Employee fix');
                        layers.push(pin);
                        if (fix.acc) layers.push(L.circle([fix.lat, fix.lng], { radius: fix.acc, color: '#ea6c44', weight: 1, fillColor: '#f7a88a', fillOpacity: 0.15 }).addTo(map));
                    }
                    if (layers.length) map.fitBounds(L.featureGroup(layers).getBounds().pad(0.3)); else map.setView([14.6108, 121.0049], 13);
                    setTimeout(() => map.invalidateSize(), 200);
                },
            };
        }
    </script>
</x-app-layout>
