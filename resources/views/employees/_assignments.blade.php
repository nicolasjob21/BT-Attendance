{{-- Project site deployment. No assignment = office / unassigned, which is a valid state. --}}
<div class="card p-6 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Project site assignment</h2>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">
                Where this employee is currently deployed. They can still clock in at the main office; punches elsewhere are recorded as an authorized alternate location.
            </p>
        </div>
        <div class="text-sm">
            @if($activeAssignment)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-100 px-3 py-1 font-medium text-brand-800 dark:bg-brand-900/40 dark:text-brand-200">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-6-5.2-6-10a6 6 0 1112 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/></svg>
                    {{ $activeAssignment->site->name }}
                </span>
                <span class="ml-1 text-xs text-gray-500 dark:text-slate-400">since {{ $activeAssignment->start_date->format('M j, Y') }}@if($activeAssignment->end_date) · until {{ $activeAssignment->end_date->format('M j, Y') }}@endif</span>
            @else
                <span class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-600 dark:bg-slate-700 dark:text-slate-300">Office / unassigned</span>
            @endif
        </div>
    </div>

    @if(session('status') && request()->routeIs('employees.edit'))
        <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200">{{ session('status') }}</div>
    @endif

    {{-- Assign / reassign --}}
    <form method="POST" action="{{ route('employees.assignments.store', $employee) }}" class="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
        @csrf
        <div>
            <label for="site_id" class="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-300">{{ $activeAssignment ? 'Move to' : 'Assign to' }} project site</label>
            <select id="site_id" name="site_id" required class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">— Choose an active project site —</option>
                @foreach($projectSites as $s)
                    @continue($activeAssignment && $s->id === $activeAssignment->site_id)
                    <option value="{{ $s->id }}" @selected(old('site_id') == $s->id)>{{ $s->name }}@if($s->type === 'temporary') (temporary)@endif</option>
                @endforeach
            </select>
            @error('site_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="start_date" class="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-300">From</label>
            <input type="date" id="start_date" name="start_date" value="{{ old('start_date', now()->toDateString()) }}" required
                   class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500 dark:[color-scheme:dark]">
            @error('start_date') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <button class="rounded-xs bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ $activeAssignment ? 'Reassign' : 'Assign' }}</button>
        <div class="sm:col-span-3">
            <input type="text" name="assignment_notes" value="{{ old('assignment_notes') }}" maxlength="500" placeholder="Notes (optional) — e.g. site foreman, expected 3 weeks"
                   class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
        @if($projectSites->isEmpty())
            <p class="sm:col-span-3 text-xs text-amber-700 dark:text-amber-300">No active project sites. <a href="{{ route('sites.create') }}" class="underline">Add one</a> first.</p>
        @endif
    </form>

    @if($activeAssignment)
        <form method="POST" action="{{ route('employees.assignments.end', [$employee, $activeAssignment]) }}"
              onsubmit="return confirm('End the assignment to {{ addslashes($activeAssignment->site->name) }} as of today? The employee reverts to office / unassigned.')">
            @csrf @method('PATCH')
            <input type="hidden" name="status" value="ended">
            <button class="text-sm font-medium text-rose-600 hover:underline dark:text-rose-300">End current assignment</button>
        </form>
    @endif

    {{-- History --}}
    @if($employee->projectAssignments->isNotEmpty())
        <div class="border-t border-gray-100 pt-4 dark:border-slate-700">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Assignment history</p>
            <ul class="divide-y divide-gray-100 text-sm dark:divide-slate-700">
                @foreach($employee->projectAssignments as $a)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div>
                            <span class="font-medium text-gray-900 dark:text-slate-100">{{ $a->site->name }}</span>
                            <span class="ml-1 text-xs text-gray-500 dark:text-slate-400">{{ $a->start_date->format('M j, Y') }} → {{ $a->end_date?->format('M j, Y') ?? 'open' }}</span>
                            @if($a->assignment_notes)<div class="text-xs text-gray-500 dark:text-slate-400">{{ $a->assignment_notes }}</div>@endif
                        </div>
                        <div class="flex items-center gap-2">
                            <x-status-badge :status="$a->status" />
                            @if($a->creator)<span class="text-[11px] text-gray-400 dark:text-slate-500">by {{ $a->creator->name }}</span>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
