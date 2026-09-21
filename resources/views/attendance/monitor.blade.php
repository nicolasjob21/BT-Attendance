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

    <div class="page space-y-4">

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
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
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
                   class="btn-app btn-md btn-secondary">Today</a>

                <input type="text" name="search" value="{{ $search }}" placeholder="Search employee…"
                       class="min-w-[160px] flex-1 rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                <button class="btn-app btn-md btn-dark">Filter</button>
                @if($search)
                    <a href="{{ $link($date) }}" class="text-sm text-gray-500 dark:text-slate-400 hover:underline">Clear</a>
                @endif
            </form>

        </div>

        {{-- Summary for the day --}}
        <div class="stat-strip">
            <x-stat :label="$isRestDay ? 'Worked today' : 'Present'" :value="$present" :hint="'of '.$rows->count().' employee(s)'" tone="success" />
            <x-stat :label="$isRestDay ? 'Day off' : 'Absent'" :value="$absent" :hint="$isRestDay ? 'rest day — no punch expected' : 'no time in yet'" :tone="$isRestDay ? 'muted' : ($absent ? 'danger' : 'success')" />
            <x-stat label="Still clocked in" :value="$stillIn" :hint="$stillIn ? 'no time out recorded' : 'everyone is out'" :tone="$stillIn ? 'brand' : 'muted'" />
            <x-stat label="Needs your check" :value="$needsCheck" :hint="$needsCheck ? 'long day or location exception' : 'nothing flagged'" :tone="$needsCheck ? 'warn' : 'muted'" />
        </div>

        <p class="text-xs text-gray-500 dark:text-slate-400">
            Showing {{ $day->isToday() ? 'today' : $day->format('l, F j, Y') }}
            @if($otMinutes) · {{ sprintf('%d:%02d', intdiv($otMinutes, 60), $otMinutes % 60) }} h overtime @endif
            @if($isRestDay)
                <span class="badge badge-danger ml-1">Rest day — anyone on site is on rest-day OT (130%)</span>
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
                                    <a href="{{ route('attendance.timesheet', ['employee' => $row['employee'], 'month' => $day->format('Y-m')]) }}"
                                       class="font-medium text-gray-900 hover:text-brand-700 hover:underline dark:text-slate-100 dark:hover:text-brand-300"
                                       title="Open this employee's monthly timesheet">{{ $row['employee']->full_name }}</a>
                                    <div class="text-xs text-gray-500 dark:text-slate-400">{{ $row['employee']->employee_no }}</div>
                                </td>

                                @foreach(['Time In' => $in, 'Time Out' => $out] as $label => $log)
                                    <td data-label="{{ $label }}" class="px-4 py-3 {{ $log ? '' : 'stack-skip' }}">
                                        @if($log)
                                            <div class="flex items-center justify-end gap-2 sm:justify-start">
                                                @if($log->photo_path)
                                                    <img src="{{ Storage::url($log->photo_path) }}" alt="selfie"
                                                         @click="$dispatch('open-lightbox', '{{ Storage::url($log->photo_path) }}')"
                                                         class="h-8 w-8 shrink-0 cursor-zoom-in rounded-full object-cover transition hover:opacity-80 hover:ring-2 hover:ring-brand-500">
                                                @endif
                                                <div class="text-right sm:text-left">
                                                    <div class="font-medium text-gray-900 dark:text-slate-100">{{ $log->logged_at->format('g:i A') }}<x-next-day :from="$day" :to="$log->logged_at" /></div>
                                                    <div class="text-xs text-gray-500 dark:text-slate-400">
                                                        @if($log->site){{ $log->site->name }}@endif
                                                        @if($log->latitude && $log->longitude)
                                                            <a href="https://www.google.com/maps?q={{ $log->latitude }},{{ $log->longitude }}" target="_blank" rel="noopener"
                                                               class="text-brand-600 hover:underline dark:text-brand-300">{{ $log->site ? '· map' : 'View map' }}</a>
                                                        @endif
                                                    </div>
                                                    @if($log->location_status)
                                                        <x-location-badge :status="$log->location_status" :verification="$log->location_verification_status" compact class="mt-0.5" />
                                                    @endif
                                                </div>
                                            </div>
                                        @else
                                            <span class="text-gray-400 dark:text-slate-500">—</span>
                                        @endif
                                    </td>
                                @endforeach

                                <td data-label="Hours" class="px-4 py-3 tabular-nums text-gray-700 dark:text-slate-200 {{ $hours ? '' : 'stack-skip' }}">
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
                                                <span class="badge badge-success">Present</span>
                                            @elseif($status === 'incomplete')
                                                <span class="badge badge-warn">No time out</span>
                                            @elseif($isRestDay)
                                                <span class="badge badge-muted">Day off</span>
                                            @else
                                                <span class="badge badge-muted">Absent</span>
                                            @endif

                                            @if($otMins > 0 && $status === 'complete')
                                                <span class="badge badge-danger">{{ !empty($row['rest_day']) ? 'Rest-day OT' : 'Overtime' }}</span>
                                            @endif
                                            @if(!empty($row['rest_day']) && $in)
                                                @if($row['rest_day_request']?->status === 'approved')
                                                    <span class="badge badge-success" title="Approved rest-day work request">OT approved</span>
                                                @elseif($row['rest_day_request'])
                                                    <span class="badge badge-warn">OT request pending</span>
                                                @else
                                                    <a href="{{ route('overtime.index') }}" title="Weekend work is paid only through an approved rest-day OT request. The employee can still file it up to 3 days later."
                                                       class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800 hover:underline dark:bg-rose-900/40 dark:text-rose-200">No OT request</a>
                                                @endif
                                            @endif

                                            @foreach($row['location_exceptions'] ?? [] as $ex)
                                                @if($ex->location_verification_status === 'pending')
                                                    <span class="badge badge-warn">Location pending</span>
                                                @elseif($ex->location_verification_status === null)
                                                    <span class="badge badge-danger">Location exception</span>
                                                @endif
                                            @endforeach

                                            @if(!empty($row['needs_verification']))
                                                @if($vstatus === 'approved')
                                                    <span class="badge badge-success">OT verified</span>
                                                @elseif($vstatus === 'rejected')
                                                    <span class="badge badge-danger">OT rejected</span>
                                                @else
                                                    <span title="13h+ day — unusual. Needs HR sign-off before the overtime is trusted."
                                                          class="badge badge-warn">Needs HR verification</span>
                                                @endif
                                            @endif
                                        </div>

                                        {{-- Location exception review: an out-of-area / no-GPS / weak-fix punch --}}
                                        @foreach($row['location_exceptions'] ?? [] as $ex)
                                            @php $lv = $ex->location_verification_status; @endphp
                                            <div class="w-full max-w-xs space-y-1 rounded-xs border border-gray-200 px-2.5 py-2 text-xs dark:border-slate-700">
                                                <div class="flex flex-wrap items-center justify-between gap-1">
                                                    <span class="font-medium text-gray-700 dark:text-slate-200">{{ $ex->log_type === 'time_in' ? 'Time in' : 'Time out' }} {{ $ex->logged_at->format('g:i A') }}</span>
                                                    <x-location-badge :status="$ex->location_status" :verification="$lv" compact />
                                                </div>
                                                <p class="text-gray-500 dark:text-slate-400">{{ $ex->location_validation_message }}</p>
                                                @if($ex->assignedSite)
                                                    <p class="text-gray-500 dark:text-slate-400">Assigned project: {{ $ex->assignedSite->name }}</p>
                                                @endif
                                                @if($ex->location_reason)
                                                    <p class="text-gray-700 dark:text-slate-200">Employee: “{{ $ex->location_reason }}”</p>
                                                @endif
                                                @if($lv && $lv !== 'pending')
                                                    <p class="text-gray-500 dark:text-slate-400">
                                                        HR {{ $lv }}@if($ex->location_remarks): “{{ $ex->location_remarks }}”@endif
                                                        <span class="block text-[11px] text-gray-400 dark:text-slate-500">— {{ $ex->locationVerifier?->name }}@if($ex->location_verified_at), {{ $ex->location_verified_at->format('M j, g:i A') }}@endif</span>
                                                    </p>
                                                    @can('approve requests')
                                                        <button type="button" x-data @click="$refs.lv{{ $ex->id }}.classList.toggle('hidden')"
                                                                class="text-[11px] text-brand-600 hover:underline dark:text-brand-300">Change decision</button>
                                                    @endcan
                                                @endif
                                                @can('approve requests')
                                                    <form method="POST" action="{{ route('attendance.verify-location', $ex) }}" x-ref="lv{{ $ex->id }}"
                                                          class="{{ ($lv && $lv !== 'pending') ? 'hidden ' : '' }}space-y-1.5 pt-1">
                                                        @csrf
                                                        <input type="text" name="remarks" maxlength="500" value="{{ $ex->location_remarks }}" placeholder="Remarks (optional)"
                                                               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-xs focus:border-brand-500 focus:ring-brand-500">
                                                        <div class="flex gap-1.5">
                                                            <button name="decision" value="approved"
                                                                    class="btn-app btn-xs btn-success">Approve location</button>
                                                            <button name="decision" value="rejected"
                                                                    class="btn-app btn-xs btn-outline-danger">Reject</button>
                                                        </div>
                                                    </form>
                                                @endcan
                                            </div>
                                        @endforeach

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
                                                                class="btn-app btn-xs btn-success">Approve</button>
                                                        <button name="decision" value="rejected"
                                                                class="btn-app btn-xs btn-outline-danger">Reject</button>
                                                    </div>
                                                </form>
                                            @endcan
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-0"><x-empty-state icon="users" title="No employees match your search" :href="$link($date)" action="Clear search" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
