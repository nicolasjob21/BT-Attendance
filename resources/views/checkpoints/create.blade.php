@php
    $editing = $campaign !== null;
    $selected = collect(old('employees', $editing ? $campaign->employees->pluck('id')->all() : []))->map(fn ($v) => (int) $v)->all();
    $chosenInstructions = old('photo_instructions', $editing ? $campaign->photo_instructions : array_slice($instructionLibrary, 0, 3));
    $custom = array_values(array_diff($chosenInstructions, $instructionLibrary));
    $employeeRows = $employees->map(fn ($e) => [
        'id' => $e->id, 'name' => $e->full_name, 'no' => $e->employee_no, 'type' => $e->employee_type,
        'site_id' => $e->activeAssignment?->site_id, 'site' => $e->activeAssignment?->site?->name,
    ])->values();
@endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">{{ $editing ? 'Edit Checkpoint Campaign' : 'Create Checkpoint Campaign' }}</h1>
    </x-slot>

    <div class="mx-auto max-w-5xl space-y-4">
        <a href="{{ $editing ? route('checkpoints.show', $campaign) : route('checkpoints.index') }}" class="text-sm text-brand-700 hover:underline dark:text-brand-300">← Back</a>

        @if($errors->any())
            <div class="rounded-xs border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-900/30 dark:text-rose-200">
                Please fix the highlighted fields. {{ $errors->first('checkpoints_per_day') }}
            </div>
        @endif

        <form method="POST" action="{{ $editing ? route('checkpoints.update', $campaign) : route('checkpoints.store') }}"
              x-data="campaignForm({
                  employees: @js($employeeRows),
                  selected: @js($selected),
                  siteId: @js((int) old('project_site_id', $campaign?->project_site_id ?? 0)),
                  start: @js(old('working_start_time', $campaign ? substr($campaign->working_start_time, 0, 5) : '08:30')),
                  end: @js(old('working_end_time', $campaign ? substr($campaign->working_end_time, 0, 5) : '17:30')),
                  perDay: @js((int) old('checkpoints_per_day', $campaign?->checkpoints_per_day ?? $defaults['checkpoints_per_day'])),
                  minGap: @js((int) old('minimum_interval_minutes', $campaign?->minimum_interval_minutes ?? $defaults['minimum_interval_minutes'])),
                  maxGap: @js((int) old('maximum_interval_minutes', $campaign?->maximum_interval_minutes ?? $defaults['maximum_interval_minutes'])),
                  window: @js((int) old('response_window_minutes', $campaign?->response_window_minutes ?? $defaults['response_window_minutes'])),
                  custom: @js($custom),
              })" class="space-y-4">
            @csrf
            @if($editing) @method('PUT') @endif

            {{-- 1. Campaign & site --}}
            <section class="card p-5">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">1 · Campaign &amp; project site</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Campaign name</label>
                        <input type="text" id="name" name="name" value="{{ old('name', $campaign?->name) }}" required maxlength="150" placeholder="e.g. NAIA Project — Afternoon Presence Verification"
                               class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="project_site_id" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Project site</label>
                        <select id="project_site_id" name="project_site_id" x-model.number="siteId" required
                                class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                            <option value="">Select a site…</option>
                            @foreach($sites as $s)
                                <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->geofence_radius_m }} m fence)</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Employees are verified against this site's geofence.</p>
                        @error('project_site_id') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="reason" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Reason for activation</label>
                        <textarea id="reason" name="reason" rows="3" required maxlength="1000" placeholder="Reports indicate that employees may be leaving the project site during working hours and returning before Time Out."
                                  class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">{{ old('reason', $campaign?->reason) }}</textarea>
                        @error('reason') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- 2. Employees --}}
            <section class="card p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">2 · Employees covered <span class="ml-1 rounded-full bg-brand-100 px-2 py-0.5 text-xs text-brand-800 dark:bg-brand-900/40 dark:text-brand-200" x-text="selected.length + ' selected'"></span></h2>
                    <div class="flex flex-wrap gap-1.5 text-xs">
                        <button type="button" @click="selectAssigned()" :disabled="!siteId" class="rounded-xs border border-brand-300 px-2.5 py-1 font-medium text-brand-700 hover:bg-brand-50 disabled:opacity-40 dark:border-brand-500/40 dark:text-brand-300">Assigned to this site</button>
                        <button type="button" @click="selectVisible()" class="rounded-xs border border-gray-300 px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200">Select shown</button>
                        <button type="button" @click="selected = []" class="rounded-xs border border-gray-300 px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200">Clear</button>
                    </div>
                </div>
                <input type="search" x-model="search" placeholder="Filter by name, number, or site…"
                       class="mt-3 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600 sm:max-w-sm">
                @error('employees') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                <div class="mt-3 max-h-72 overflow-y-auto rounded-xs border border-gray-200 dark:border-slate-700">
                    <template x-for="e in visible()" :key="e.id">
                        <label class="flex cursor-pointer items-center gap-3 border-b border-gray-100 px-3 py-2 text-sm last:border-0 hover:bg-gray-50 dark:border-slate-700/60 dark:hover:bg-slate-800/40">
                            <input type="checkbox" name="employees[]" :value="e.id" x-model.number="selected" class="rounded-xs border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600">
                            <span class="min-w-0 flex-1">
                                <span class="font-medium text-gray-900 dark:text-slate-100" x-text="e.name"></span>
                                <span class="ml-1 text-xs text-gray-500 dark:text-slate-400" x-text="e.no"></span>
                            </span>
                            <span class="text-xs" :class="e.site_id && e.site_id === siteId ? 'font-medium text-emerald-700 dark:text-emerald-300' : 'text-gray-400 dark:text-slate-500'" x-text="e.site || 'Office / unassigned'"></span>
                        </label>
                    </template>
                    <p x-show="visible().length === 0" class="px-3 py-6 text-center text-sm text-gray-400 dark:text-slate-500">No employees match.</p>
                </div>
            </section>

            {{-- 3. Schedule --}}
            <section class="card p-5">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">3 · Checkpoint schedule &amp; response window</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Start date</label>
                        <input type="date" name="start_date" value="{{ old('start_date', $campaign?->start_date?->toDateString() ?? today()->toDateString()) }}" required
                               class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        @error('start_date') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">End date</label>
                        <input type="date" name="end_date" value="{{ old('end_date', $campaign?->end_date?->toDateString() ?? today()->addDays(4)->toDateString()) }}" required
                               class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        @error('end_date') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Working hours start</label>
                        <input type="time" name="working_start_time" x-model="start" required
                               class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        @error('working_start_time') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Working hours end</label>
                        <input type="time" name="working_end_time" x-model="end" required
                               class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        @error('working_end_time') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Checkpoints per day</label>
                        <input type="number" name="checkpoints_per_day" x-model.number="perDay" min="1" max="12" required
                               class="w-full rounded-xs border-gray-300 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        @error('checkpoints_per_day') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Min. interval (min)</label>
                        <input type="number" name="minimum_interval_minutes" x-model.number="minGap" min="5" max="720" required
                               class="w-full rounded-xs border-gray-300 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        @error('minimum_interval_minutes') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Max. interval (min)</label>
                        <input type="number" name="maximum_interval_minutes" x-model.number="maxGap" min="5" max="720" required
                               class="w-full rounded-xs border-gray-300 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        @error('maximum_interval_minutes') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Response window (min)</label>
                        <input type="number" name="response_window_minutes" x-model.number="window" min="3" max="60" required
                               class="w-full rounded-xs border-gray-300 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        @error('response_window_minutes') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <label class="mt-3 inline-flex items-center gap-2 text-sm text-gray-700 dark:text-slate-200">
                    <input type="hidden" name="include_weekends" value="0">
                    <input type="checkbox" name="include_weekends" value="1" @checked(old('include_weekends', $campaign?->include_weekends ?? false)) class="rounded-xs border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600">
                    Also run on weekends
                </label>
                <div class="mt-3 rounded-xs border px-3 py-2 text-xs" :class="feasible() ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/20 dark:text-emerald-200' : 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900/50 dark:bg-rose-900/20 dark:text-rose-200'">
                    <span x-text="summary()"></span>
                    <span class="block text-[11px] opacity-80">Exact times are generated randomly on the server for each employee each day and are never shown in advance.</span>
                </div>
            </section>

            {{-- 4. Photo instructions --}}
            <section class="card p-5">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">4 · Rotating photo instructions</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">One instruction is picked at random for each checkpoint. The employee must capture it with the live camera — gallery uploads are not accepted.</p>
                @error('photo_instructions') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach($instructionLibrary as $i)
                        <label class="flex cursor-pointer items-center gap-2 rounded-xs border border-gray-200 px-3 py-2 text-sm hover:bg-gray-50 dark:border-slate-700 dark:hover:bg-slate-800/40">
                            <input type="checkbox" name="photo_instructions[]" value="{{ $i }}" @checked(in_array($i, $chosenInstructions, true)) class="rounded-xs border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600">
                            <span class="text-gray-800 dark:text-slate-100">{{ $i }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="mt-3 space-y-2">
                    <template x-for="(c, idx) in custom" :key="idx">
                        <div class="flex gap-2">
                            <input type="text" name="photo_instructions[]" x-model="custom[idx]" maxlength="150" placeholder="Custom instruction, e.g. Capture the tower crane base."
                                   class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                            <button type="button" @click="custom.splice(idx, 1)" class="rounded-xs border border-gray-300 px-2 text-xs text-gray-600 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300">Remove</button>
                        </div>
                    </template>
                    <button type="button" @click="custom.push('')" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">+ Add custom instruction</button>
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <a href="{{ $editing ? route('checkpoints.show', $campaign) : route('checkpoints.index') }}" class="rounded-xs border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">Cancel</a>
                <button type="submit" class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-5 py-2 text-sm font-semibold text-white hover:from-brand-700 hover:to-accent-600">
                    {{ $editing ? 'Save changes' : 'Save & review' }}
                </button>
            </div>
            <p class="text-right text-xs text-gray-500 dark:text-slate-400">The campaign is saved as a draft. You activate or schedule it from the review page after confirming.</p>
        </form>
    </div>

    <script>
        function campaignForm(init) {
            return {
                employees: init.employees, selected: init.selected, siteId: init.siteId, search: '',
                start: init.start, end: init.end, perDay: init.perDay, minGap: init.minGap, maxGap: init.maxGap, window: init.window,
                custom: init.custom,

                visible() {
                    const q = this.search.trim().toLowerCase();
                    if (!q) return this.employees;
                    return this.employees.filter(e => (e.name + ' ' + e.no + ' ' + (e.site || '')).toLowerCase().includes(q));
                },
                selectAssigned() {
                    const ids = this.employees.filter(e => e.site_id === this.siteId).map(e => e.id);
                    this.selected = [...new Set([...this.selected, ...ids])];
                },
                selectVisible() {
                    this.selected = [...new Set([...this.selected, ...this.visible().map(e => e.id)])];
                },
                minutes(t) { const [h, m] = (t || '0:0').split(':').map(Number); return h * 60 + m; },
                windowLen() { return this.minutes(this.end) - this.minutes(this.start); },
                needed() { return Math.max(0, (this.perDay - 1)) * this.minGap + this.window; },
                feasible() { return this.windowLen() > 0 && this.minGap <= this.maxGap && this.needed() <= this.windowLen(); },
                summary() {
                    if (this.windowLen() <= 0) return 'Working hours must end after they start.';
                    if (this.minGap > this.maxGap) return 'Minimum interval cannot exceed the maximum interval.';
                    if (!this.feasible()) return `Not enough time: ${this.perDay} checkpoint(s) at least ${this.minGap} min apart plus a ${this.window}-min window need ${this.needed()} min, but the window is ${this.windowLen()} min.`;
                    return `${this.perDay} random checkpoint(s) per day per employee between ${this.start} and ${this.end}, ${this.minGap}–${this.maxGap} min apart, each open for ${this.window} minutes.`;
                },
            };
        }
    </script>
</x-app-layout>
