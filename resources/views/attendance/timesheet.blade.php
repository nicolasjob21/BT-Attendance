@use('App\Support\WorkHours')

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Monthly Timesheet</h1>
    </x-slot>
    <x-slot name="back">{{ $employee->id === auth()->user()->employee?->id ? route('attendance.index') : (auth()->user()->can('view team reports') ? route('attendance.monitor') : route('dashboard')) }}</x-slot>
    <x-slot name="backLabel">Back</x-slot>

    @php
        $prev = $month->copy()->subMonth()->format('Y-m');
        $next = $month->copy()->addMonth()->format('Y-m');
        $link = fn ($m) => route('attendance.timesheet', ['employee' => $employee, 'month' => $m]);
        $fmtH = fn (int $mins) => $mins > 0 ? WorkHours::label($mins) : '—';
    @endphp

    <div class="page space-y-4">

        {{-- Header: who + month stepper --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="font-display text-xl font-bold text-gray-900 dark:text-slate-100">{{ $employee->full_name }}</p>
                <p class="text-xs text-gray-500 dark:text-slate-400">
                    {{ $employee->employee_no }} · {{ $employee->schedule?->name ?? 'No schedule' }}
                    @if($employee->assignedSite()) · assigned to {{ $employee->assignedSite()->name }} @endif
                </p>
            </div>
            <form method="GET" action="{{ route('attendance.timesheet', $employee) }}" class="flex items-center gap-2">
                <div class="inline-flex items-center overflow-hidden rounded-xs border border-gray-300 dark:border-slate-600">
                    <a href="{{ $link($prev) }}" aria-label="Previous month" class="px-2 py-2 text-gray-500 hover:bg-gray-100 dark:text-slate-400 dark:hover:bg-slate-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </a>
                    <input type="month" name="month" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()"
                           class="border-0 bg-transparent px-2 py-1.5 text-sm focus:ring-0 dark:[color-scheme:dark]">
                    <a href="{{ $link($next) }}" aria-label="Next month" class="px-2 py-2 text-gray-500 hover:bg-gray-100 dark:text-slate-400 dark:hover:bg-slate-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>
                <a href="{{ $link(now()->format('Y-m')) }}" class="btn-app btn-md btn-secondary">This month</a>
                @if($canReview)
                    <a href="{{ route('attendance.monitor', ['date' => $month->isSameMonth(now()) ? now()->toDateString() : $month->toDateString()]) }}" class="text-sm text-brand-600 hover:underline dark:text-brand-300">Daily log</a>
                @endif
            </form>
        </div>

        {{-- Month summary --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach([
                ['Days present', $totals['present'] . ' / ' . $expectedDays, 'expected so far', 'text-emerald-700 dark:text-emerald-300'],
                ['Absent', $totals['absent'], 'working days, no punch', $totals['absent'] ? 'text-rose-600 dark:text-rose-300' : ''],
                ['On leave', $totals['leave'], 'approved leave days', ''],
                ['Hours worked', $fmtH($totals['worked']), 'all sessions', ''],
                ['Regular', $fmtH($totals['regular']), 'first 8h per weekday', ''],
                ['Overtime', $fmtH($totals['overtime']), 'incl. rest days', $totals['overtime'] ? 'text-accent-600 dark:text-accent-300' : ''],
            ] as [$label, $value, $hint, $color])
                <div class="card p-3">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-slate-400">{{ $label }}</p>
                    <p class="mt-1 font-display text-xl font-bold tabular-nums text-gray-900 dark:text-slate-100 {{ $color }}">{{ $value }}</p>
                    <p class="text-[11px] text-gray-400 dark:text-slate-500">{{ $hint }}</p>
                </div>
            @endforeach
        </div>
        @if($totals['exceptions'])
            <p class="text-xs text-rose-600 dark:text-rose-300">{{ $totals['exceptions'] }} day(s) have a punch outside the authorized area / without GPS — see the location column.</p>
        @endif

        {{-- Day-by-day --}}
        <div class="overflow-hidden card">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Time In</th>
                            <th class="px-4 py-3">Time Out</th>
                            <th class="px-4 py-3">Hours</th>
                            <th class="px-4 py-3">Location</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @foreach($days as $day)
                            @php $muted = in_array($day['status'], ['rest', 'upcoming'], true) && ! $day['in']; @endphp
                            <tr class="{{ $muted ? 'bg-gray-50/60 text-gray-400 dark:bg-slate-800/30 dark:text-slate-500' : '' }} {{ $day['date']->isToday() ? 'ring-1 ring-inset ring-brand-300/60' : '' }}">
                                <td class="cell-head px-4 py-2.5 whitespace-nowrap">
                                    <span class="font-medium {{ $muted ? '' : 'text-gray-900 dark:text-slate-100' }}">{{ $day['date']->format('D') }}</span>
                                    <span class="{{ $muted ? '' : 'text-gray-500 dark:text-slate-400' }}"> {{ $day['date']->format('M j') }}</span>
                                    @if($day['rest_day'] && $day['in'])
                                        <span class="badge badge-danger ml-1">rest day</span>
                                    @endif
                                </td>

                                @foreach(['Time In' => $day['in'], 'Time Out' => $day['out']] as $label => $log)
                                    <td data-label="{{ $label }}" class="px-4 py-2.5 whitespace-nowrap">
                                        @if($log)
                                            <div class="flex items-center justify-end gap-2 sm:justify-start">
                                                @if($log->photo_path)
                                                    <img src="{{ Storage::url($log->photo_path) }}" alt=""
                                                         @click="$dispatch('open-lightbox', '{{ Storage::url($log->photo_path) }}')"
                                                         class="h-7 w-7 shrink-0 cursor-zoom-in rounded-full object-cover hover:ring-2 hover:ring-brand-500">
                                                @endif
                                                <span class="tabular-nums text-gray-900 dark:text-slate-100">{{ $log->logged_at->format('g:i A') }}</span>
                                                @if($label === 'Time Out' && ! $log->logged_at->isSameDay($day['date']))
                                                    <span class="text-[10px] text-gray-400 dark:text-slate-500">+1d</span>
                                                @endif
                                            </div>
                                        @elseif($day['status'] === 'open' && $label === 'Time Out')
                                            <span class="text-xs text-amber-600 dark:text-amber-300">still clocked in</span>
                                        @else
                                            <span class="text-gray-300 dark:text-slate-600">—</span>
                                        @endif
                                    </td>
                                @endforeach

                                <td data-label="Hours" class="px-4 py-2.5 tabular-nums">
                                    @if($day['minutes'] > 0)
                                        <div class="font-medium text-gray-900 dark:text-slate-100">{{ WorkHours::label($day['minutes']) }}</div>
                                        @if($day['overtime'] > 0)
                                            <div class="text-xs text-gray-500 dark:text-slate-400">
                                                @if($day['rest_day'])
                                                    <span class="font-medium text-accent-600 dark:text-accent-300">OT {{ WorkHours::label($day['overtime']) }}</span>
                                                @else
                                                    Reg {{ WorkHours::label($day['regular']) }} · <span class="font-medium text-accent-600 dark:text-accent-300">OT {{ WorkHours::label($day['overtime']) }}</span>
                                                @endif
                                                @if($day['ot_request'])
                                                    <span class="text-emerald-600 dark:text-emerald-300" title="Pre-approved overtime">✓ approved</span>
                                                @else
                                                    <span class="{{ $day['rest_day'] ? 'text-rose-600 dark:text-rose-300' : 'text-gray-400 dark:text-slate-500' }}" title="{{ $day['rest_day'] ? 'Weekend work is paid only through an approved rest-day OT request' : 'No approved OT request for this day' }}">no request</span>
                                                @endif
                                            </div>
                                        @endif
                                        @if($day['sessions'] > 1)
                                            <div class="text-[10px] text-gray-400 dark:text-slate-500">{{ $day['sessions'] }} sessions</div>
                                        @endif
                                    @else
                                        <span class="text-gray-300 dark:text-slate-600">—</span>
                                    @endif
                                </td>

                                <td data-label="Location" class="px-4 py-2.5">
                                    @if($day['in'])
                                        <div class="text-gray-700 dark:text-slate-200">{{ $day['site'] ?? '—' }}</div>
                                        @if($day['exception'])
                                            <x-location-badge :status="$day['exception']->location_status" :verification="$day['exception']->location_verification_status" compact class="mt-0.5" />
                                        @elseif($day['in']->location_status)
                                            <x-location-badge :status="$day['in']->location_status" :verification="$day['in']->location_verification_status" compact class="mt-0.5" />
                                        @endif
                                    @else
                                        <span class="text-gray-300 dark:text-slate-600">—</span>
                                    @endif
                                </td>

                                <td data-label="Status" class="px-4 py-2.5">
                                    @switch($day['status'])
                                        @case('present')
                                            <span class="badge badge-success">Present</span>
                                            @break
                                        @case('open')
                                            <span class="badge badge-info">Clocked in</span>
                                            @break
                                        @case('incomplete')
                                            <span class="badge badge-warn">No time out</span>
                                            @break
                                        @case('leave')
                                            <span class="badge badge-info">
                                                Leave{{ $day['leave']?->leaveType?->code ? ' · ' . $day['leave']->leaveType->code : '' }}
                                            </span>
                                            @break
                                        @case('absent')
                                            <span class="badge badge-danger">Absent</span>
                                            @break
                                        @case('rest')
                                            <span class="text-xs">Rest day</span>
                                            @break
                                        @default
                                            <span class="text-xs">—</span>
                                    @endswitch
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50 text-xs font-semibold dark:bg-slate-800/60">
                        <tr>
                            <td class="px-4 py-2.5 text-gray-600 dark:text-slate-300" colspan="3">Month total</td>
                            <td class="px-4 py-2.5 tabular-nums text-gray-900 dark:text-slate-100">
                                {{ $fmtH($totals['worked']) }}
                                <span class="font-normal text-gray-500 dark:text-slate-400">· Reg {{ $fmtH($totals['regular']) }} · OT {{ $fmtH($totals['overtime']) }}</span>
                            </td>
                            <td class="px-4 py-2.5" colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
