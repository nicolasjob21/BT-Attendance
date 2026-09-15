@php
    $editing = $campaign !== null;
    $selected = collect(old('employees', $editing ? $campaign->employees->pluck('id')->all() : []))->map(fn ($v) => (int) $v)->all();
    $employeeRows = $employees->map(fn ($e) => [
        'id' => $e->id, 'name' => $e->full_name, 'no' => $e->employee_no, 'type' => $e->employee_type,
        'site_id' => $e->activeAssignment?->site_id, 'site' => $e->activeAssignment?->site?->name,
    ])->values();
@endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">{{ $editing ? 'Edit Checkpoint' : 'Create Checkpoint' }}</h1>
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-4">
        <a href="{{ $editing ? route('checkpoints.show', $campaign) : route('checkpoints.index') }}" class="text-sm text-brand-700 hover:underline dark:text-brand-300">← Back</a>

        @if($errors->any())
            <div class="rounded-xs border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-900/30 dark:text-rose-200">Please fix the highlighted fields.</div>
        @endif

        <form method="POST" action="{{ $editing ? route('checkpoints.update', $campaign) : route('checkpoints.store') }}"
              x-data="checkpointForm({ employees: @js($employeeRows), selected: @js($selected), siteId: @js((int) old('project_site_id', $campaign?->project_site_id ?? 0)) })" class="space-y-4">
            @csrf
            @if($editing) @method('PUT') @endif

            <section class="card p-5">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">1 · Project site &amp; instruction</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="project_site_id" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Project site</label>
                        <select id="project_site_id" name="project_site_id" x-model.number="siteId" required class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                            <option value="">Select a site…</option>
                            @foreach($sites as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->geofence_radius_m }} m fence)</option>@endforeach
                        </select>
                        @error('project_site_id') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="name" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Checkpoint name</label>
                        <input type="text" id="name" name="name" value="{{ old('name', $campaign?->name) }}" required maxlength="150" placeholder="e.g. NAIA Project — Afternoon presence check"
                               class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="instruction" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Checkpoint instruction</label>
                        <input type="text" id="instruction" name="instruction" list="instruction-library" value="{{ old('instruction', $campaign?->instruction ?? ($instructionLibrary[0] ?? '')) }}" required maxlength="200"
                               class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        <datalist id="instruction-library">@foreach($instructionLibrary as $i)<option value="{{ $i }}">@endforeach</datalist>
                        <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">What every employee must photograph with the live camera. Pick from the library or type your own.</p>
                        @error('instruction') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="response_window_minutes" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Response window (minutes)</label>
                        <input type="number" id="response_window_minutes" name="response_window_minutes" min="3" max="120" value="{{ old('response_window_minutes', $campaign?->response_window_minutes ?? $defaults['response_window_minutes']) }}" required
                               class="w-full rounded-xs border-gray-300 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Deadline = start time + this window, the same for everyone.</p>
                        @error('response_window_minutes') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="reason" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Reason <span class="text-gray-400 dark:text-slate-500">(optional)</span></label>
                        <input type="text" id="reason" name="reason" value="{{ old('reason', $campaign?->reason) }}" maxlength="1000" placeholder="e.g. Reports of staff leaving the site after lunch"
                               class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                    </div>
                </div>
            </section>

            <section class="card p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">2 · Employees included <span class="ml-1 rounded-full bg-brand-100 px-2 py-0.5 text-xs text-brand-800 dark:bg-brand-900/40 dark:text-brand-200" x-text="selected.length + ' selected'"></span></h2>
                    <div class="flex flex-wrap gap-1.5 text-xs">
                        <button type="button" @click="selectAssigned()" :disabled="!siteId" class="rounded-xs border border-brand-300 px-2.5 py-1 font-medium text-brand-700 hover:bg-brand-50 disabled:opacity-40 dark:border-brand-500/40 dark:text-brand-300">Assigned to this site</button>
                        <button type="button" @click="selectVisible()" class="rounded-xs border border-gray-300 px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200">Select shown</button>
                        <button type="button" @click="selected = []" class="rounded-xs border border-gray-300 px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200">Clear</button>
                    </div>
                </div>
                <input type="search" x-model="search" placeholder="Filter by name, number, or site…" class="mt-3 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600 sm:max-w-sm">
                @error('employees') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                <div class="mt-3 max-h-80 overflow-y-auto rounded-xs border border-gray-200 dark:border-slate-700">
                    <template x-for="e in visible()" :key="e.id">
                        <label class="flex cursor-pointer items-center gap-3 border-b border-gray-100 px-3 py-2 text-sm last:border-0 hover:bg-gray-50 dark:border-slate-700/60 dark:hover:bg-slate-800/40">
                            <input type="checkbox" name="employees[]" :value="e.id" x-model.number="selected" class="rounded-xs border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600">
                            <span class="min-w-0 flex-1"><span class="font-medium text-gray-900 dark:text-slate-100" x-text="e.name"></span> <span class="ml-1 text-xs text-gray-500 dark:text-slate-400" x-text="e.no"></span></span>
                            <span class="text-xs" :class="e.site_id && e.site_id === siteId ? 'font-medium text-emerald-700 dark:text-emerald-300' : 'text-gray-400 dark:text-slate-500'" x-text="e.site || 'Office / unassigned'"></span>
                        </label>
                    </template>
                    <p x-show="visible().length === 0" class="px-3 py-6 text-center text-sm text-gray-400 dark:text-slate-500">No employees match.</p>
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <a href="{{ $editing ? route('checkpoints.show', $campaign) : route('checkpoints.index') }}" class="rounded-xs border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">Cancel</a>
                <button type="submit" class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-5 py-2 text-sm font-semibold text-white hover:from-brand-700 hover:to-accent-600">{{ $editing ? 'Save changes' : 'Save & review employees' }}</button>
            </div>
            <p class="text-right text-xs text-gray-500 dark:text-slate-400">Saved as a draft. On the next page you review the employee list, then <strong>activate now</strong> or <strong>set a start time</strong>.</p>
        </form>
    </div>

    <script>
        function checkpointForm(init) {
            return {
                employees: init.employees, selected: init.selected, siteId: init.siteId, search: '',
                visible() {
                    const q = this.search.trim().toLowerCase();
                    return q ? this.employees.filter(e => (e.name + ' ' + e.no + ' ' + (e.site || '')).toLowerCase().includes(q)) : this.employees;
                },
                selectAssigned() { this.selected = [...new Set([...this.selected, ...this.employees.filter(e => e.site_id === this.siteId).map(e => e.id)])]; },
                selectVisible() { this.selected = [...new Set([...this.selected, ...this.visible().map(e => e.id)])]; },
            };
        }
    </script>
</x-app-layout>
