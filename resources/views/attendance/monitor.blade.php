@use('App\Support\WorkHours')

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Attendance Log</h1>
    </x-slot>

    @php
        $day = \Illuminate\Support\Carbon::parse($date);
        $prev = $day->copy()->subDay()->toDateString();
        $next = $day->copy()->addDay()->toDateString();
        $link = fn ($d) => route('attendance.monitor', array_filter(['date' => $d, 'search' => $search]));
    @endphp

    <div class="mx-auto max-w-6xl space-y-4">

        @if(session('status'))
            <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200">
                {{ session('status') }}
            </div>
        @endif
        @if($errors->any())
            <div class="rounded-xs border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-900/30 dark:text-rose-200">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" action="{{ route('attendance.monitor') }}" class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                {{-- Date picker + day stepper --}}
                <div class="inline-flex items-center overflow-hidden rounded-xs border border-gray-300 dark:border-slate-600">
                    <a href="{{ $link($prev) }}" aria-label="Previous day"
                       class="px-2 py-2 text-gray-500 hover:bg-gray-100 dark:text-slate-400 dark:hover:bg-slate-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </a>
                    <input type="date" name="date" value="{{ $date }}" onchange="this.form.submit()"
                           class="border-0 bg-transparent px-2 py-1.5 text-sm focus:ring-0 dark:[color-scheme:dark]">
                    <a href="{{ $link($next) }}" aria-label="Next day"
                       class="px-2 py-2 text-gray-500 hover:bg-gray-100 dark:text-slate-400 dark:hover:bg-slate-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>

                <a href="{{ $link(now()->toDateString()) }}"
                   class="rounded-xs border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">Today</a>

                <input type="text" name="search" value="{{ $search }}" placeholder="Search employee…"
                       class="min-w-[160px] flex-1 rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                <button class="rounded-xs bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Filter</button>
                @if($search)
                    <a href="{{ $link($date) }}" class="text-sm text-gray-500 dark:text-slate-400 hover:underline">Clear</a>
                @endif
            </form>

            {{-- Summary --}}
            <div class="flex items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">{{ $present }} present</span>
                <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 font-medium text-gray-600 dark:bg-slate-700 dark:text-slate-300">{{ $absent }} absent</span>
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-slate-400">
            Showing {{ $day->isToday() ? 'today' : $day->format('l, F j, Y') }} · {{ $rows->count() }} employee(s)
            @if($isRestDay)
                <span class="ml-1 inline-flex items-center rounded-full bg-accent-100 px-2 py-0.5 text-[11px] font-medium text-accent-800 dark:bg-accent-900/40 dark:text-accent-200">Rest day — all hours are overtime</span>
            @endif
        </p>

        {{-- Table --}}
        <div class="overflow-hidden card">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Employee</th>
                            <th class="px-4 py-3">Time In</th>
                            <th class="px-4 py-3">Time Out</th>
                            <th class="px-4 py-3">Hours</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($rows as $row)
                            @php
                                $in = $row['time_in']; $out = $row['time_out'];
                                $status = $in && $out ? 'complete' : ($in ? 'incomplete' : 'absent');
                                $regMins = $row['regular_minutes'] ?? 0;
                                $otMins = $row['ot_minutes'] ?? 0;
                                $hours = ($in && $out) ? WorkHours::label($row['minutes'] ?? 0) : null;
                            @endphp
                            <tr>
                                <td class="cell-head px-4 py-3">
                                    <div class="font-medium text-gray-900 dark:text-slate-100">{{ $row['employee']->full_name }}</div>
                                    <div class="text-xs text-gray-500 dark:text-slate-400">{{ $row['employee']->employee_no }}</div>
                                </td>

                                @foreach(['Time In' => $in, 'Time Out' => $out] as $label => $log)
                                    <td data-label="{{ $label }}" class="px-4 py-3">
                                        @if($log)
                                            <div class="flex items-center justify-end gap-2 sm:justify-start">
                                                @if($log->photo_path)
                                                    <img src="{{ Storage::url($log->photo_path) }}" alt="selfie" class="h-8 w-8 shrink-0 rounded-full object-cover">
                                                @endif
                                                <div class="text-right sm:text-left">
                                                    <div class="font-medium text-gray-900 dark:text-slate-100">{{ $log->logged_at->format('g:i A') }}</div>
                                                    @if($log->latitude && $log->longitude)
                                                        <a href="https://www.google.com/maps?q={{ $log->latitude }},{{ $log->longitude }}" target="_blank" rel="noopener"
                                                           class="text-xs text-brand-600 hover:underline dark:text-brand-300">View map</a>
                                                    @endif
                                                </div>
                                            </div>
                                        @else
                                            <span class="text-gray-400 dark:text-slate-500">—</span>
                                        @endif
                                    </td>
                                @endforeach

                                <td data-label="Hours" class="px-4 py-3 tabular-nums text-gray-700 dark:text-slate-200">
                                    @if($hours)
                                        <div class="font-medium">{{ $hours }}</div>
                                        @if($otMins > 0)
                                            <div class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">
                                                @if(!empty($row['rest_day']))
                                                    <span class="font-medium text-accent-600 dark:text-accent-300">OT {{ WorkHours::label($otMins) }}</span> (rest day)
                                                @else
                                                    Reg {{ WorkHours::label($regMins) }}
                                                    · <span class="font-medium text-accent-600 dark:text-accent-300">OT {{ WorkHours::label($otMins) }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>

                                <td data-label="Status" class="px-4 py-3 align-top">
                                    @php $vstatus = $row['verification_status'] ?? null; @endphp
                                    <div class="flex flex-col items-end gap-1.5 sm:items-start">
                                        <div class="flex flex-wrap items-center justify-end gap-1 sm:justify-start">
                                            @if($status === 'complete')
                                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Present</span>
                                            @elseif($status === 'incomplete')
                                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">No time out</span>
                                            @else
                                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-500 dark:bg-slate-700 dark:text-slate-300">Absent</span>
                                            @endif

                                            @if($otMins > 0 && $status === 'complete')
                                                <span class="inline-flex items-center rounded-full bg-accent-100 px-2.5 py-0.5 text-xs font-medium text-accent-800 dark:bg-accent-900/40 dark:text-accent-200">{{ !empty($row['rest_day']) ? 'Rest-day OT' : 'Overtime' }}</span>
                                            @endif

                                            @if(!empty($row['needs_verification']))
                                                @if($vstatus === 'approved')
                                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">OT verified</span>
                                                @elseif($vstatus === 'rejected')
                                                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">OT rejected</span>
                                                @else
                                                    <span title="13h+ day — unusual. Needs HR sign-off before the overtime is trusted."
                                                          class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Needs HR verification</span>
                                                @endif
                                            @endif
                                        </div>

                                        {{-- Verification detail / action --}}
                                        @if(!empty($row['needs_verification']))
                                            @if($vstatus)
                                                <p class="max-w-xs text-right text-xs text-gray-500 dark:text-slate-400 sm:text-left">
                                                    “{{ $row['verification_remarks'] }}”
                                                    <span class="block text-[11px] text-gray-400 dark:text-slate-500">— {{ $row['verified_by'] }}@if($row['verified_at']), {{ $row['verified_at']->format('M j, g:i A') }}@endif</span>
                                                </p>
                                                @can('approve requests')
                                                    <button type="button" x-data @click="$refs.v{{ $row['verify_log_id'] }}.classList.toggle('hidden')"
                                                            class="text-[11px] text-brand-600 hover:underline dark:text-brand-300">Change decision</button>
                                                @endcan
                                            @endif

                                            @can('approve requests')
                                                <form method="POST" action="{{ route('attendance.verify', $row['verify_log_id']) }}"
                                                      x-ref="v{{ $row['verify_log_id'] }}"
                                                      class="{{ $vstatus ? 'hidden ' : '' }}mt-0.5 w-full max-w-xs space-y-1.5">
                                                    @csrf
                                                    <textarea name="remarks" rows="2" required maxlength="500"
                                                              placeholder="Reason for the overtime (required)…"
                                                              class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-xs focus:border-brand-500 focus:ring-brand-500">{{ $row['verification_remarks'] }}</textarea>
                                                    <div class="flex gap-1.5">
                                                        <button name="decision" value="approved"
                                                                class="rounded-xs bg-emerald-600 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-700">Approve</button>
                                                        <button name="decision" value="rejected"
                                                                class="rounded-xs border border-rose-300 px-3 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-300 dark:hover:bg-rose-900/30">Reject</button>
                                                    </div>
                                                </form>
                                            @endcan
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">No employees match your search.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
