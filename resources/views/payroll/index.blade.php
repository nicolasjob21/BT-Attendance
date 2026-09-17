<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Payroll</h1>
    </x-slot>
    <x-slot name="back">{{ route('dashboard') }}</x-slot>
    <x-slot name="backLabel">Back to Dashboard</x-slot>

    <div class="page space-y-5">

        {{-- Automation (Super Admin) --}}
        @if($canAutomate)
            <form method="POST" action="{{ route('payroll.settings') }}" class="card p-5" x-data="{ on: {{ $autoEnabled ? 'true' : 'false' }}, delay: {{ $settings['generate_delay_days'] }} }">
                @csrf @method('PUT')
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-3">
                            <p class="eyebrow">Payroll automation</p>
                            <span class="badge {{ $autoEnabled ? 'badge-success' : 'badge-neutral' }}"><i class="dot {{ $autoEnabled ? 'animate-pulse' : '' }}"></i>{{ $autoEnabled ? 'On' : 'Off' }}</span>
                        </div>
                        <p class="mt-1 text-sm text-gray-600 dark:text-slate-300">
                            Periods are created automatically (1–15 and 16–end of month). When automation is on, each cutoff's payroll is computed for every active employee
                            <b class="text-gray-900 dark:text-slate-100" x-text="delay + ' day(s)'">{{ $settings['generate_delay_days'] }} day(s)</b> after it ends, and everyone who can run payroll gets a notification to review, adjust and close it.
                            Lines you edited by hand are never overwritten.
                        </p>
                    </div>
                    <label class="inline-flex cursor-pointer items-center gap-3">
                        <span class="text-sm font-medium text-gray-700 dark:text-slate-200">Automate payroll</span>
                        <input type="checkbox" name="auto_enabled" value="1" x-model="on" class="peer sr-only">
                        <span class="relative h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-emerald-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-400 dark:bg-slate-600">
                            <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition" :class="on && 'translate-x-5'"></span>
                        </span>
                    </label>
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="generate_delay_days" class="block text-xs font-medium text-gray-700 dark:text-slate-200">Compute payroll … days after the cutoff ends</label>
                        <input type="number" id="generate_delay_days" name="generate_delay_days" min="0" max="15" value="{{ $settings['generate_delay_days'] }}" x-model="delay"
                               class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        <p class="mt-1 text-[11px] text-gray-500 dark:text-slate-400">Gives time for late punches and OT approvals.</p>
                    </div>
                    <div>
                        <label for="pay_date_offset_days" class="block text-xs font-medium text-gray-700 dark:text-slate-200">Pay date … days after the cutoff ends</label>
                        <input type="number" id="pay_date_offset_days" name="pay_date_offset_days" min="0" max="31" value="{{ $settings['pay_date_offset_days'] }}"
                               class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        <p class="mt-1 text-[11px] text-gray-500 dark:text-slate-400">Used when new periods are created.</p>
                    </div>
                    <div class="flex items-end justify-end">
                        <button class="btn-app btn-md btn-brand">Save automation</button>
                    </div>
                </div>
            </form>
        @endif

        {{-- Period selector + actions --}}
        <div class="flex flex-wrap items-center justify-between gap-3 card p-4">
            <form method="GET" action="{{ route('payroll.index') }}" class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                <label for="period" class="text-sm text-gray-600 dark:text-slate-300">Period</label>
                <select id="period" name="period" onchange="this.form.submit()"
                        class="w-full min-w-0 max-w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500 sm:w-auto">
                    @forelse($periods as $p)
                        <option value="{{ $p->id }}" @selected($selected && $selected->id === $p->id)>
                            {{ $p->label() }} ({{ $p->cutoff_type === 'first_half' ? '1–15' : '16–end' }}) · pay {{ $p->pay_date?->format('M j') }}
                        </option>
                    @empty
                        <option value="">No periods yet</option>
                    @endforelse
                </select>
                @if($selected)
                    <x-status-badge :status="$selected->status" />
                    @if($selected->generated_at)
                        <span class="text-xs text-gray-500 dark:text-slate-400">computed {{ $selected->generated_at->format('M j, g:i A') }} · {{ $selected->generated_by === 'auto' ? 'automatically' : 'manually' }}</span>
                    @endif
                @endif
            </form>

            <div class="flex flex-wrap items-center gap-2">
                @can('manage payroll rates')
                    <a href="{{ route('payroll.rates') }}" class="btn-app btn-md btn-secondary">Payroll rates</a>
                @endcan
                <form method="POST" action="{{ route('payroll.periods.create') }}">
                    @csrf
                    <button class="btn-app btn-md btn-secondary">+ Next period</button>
                </form>
                @if($selected && ! $selected->isClosed())
                    <form method="POST" action="{{ route('payroll.generate', $selected) }}"
                          onsubmit="return confirm('Compute payroll for all active employees in this period? Manually adjusted lines are kept.')">
                        @csrf
                        <button class="btn-app btn-md btn-brand">{{ $items->isEmpty() ? 'Run payroll' : 'Recalculate' }}</button>
                    </form>
                    @if($items->isNotEmpty())
                        <x-confirm-action :action="route('payroll.close', $selected)" title="Close this period?" size="md"
                            message="Payslips become final. No more recalculation or edits for {{ $selected->label() }}." button="Close period" tone="rose" />
                    @endif
                @elseif($selected && $selected->isClosed())
                    <span class="text-xs text-gray-500 dark:text-slate-400">Closed {{ $selected->closed_at?->format('M j, g:i A') }}{{ $selected->closer ? ' by '.$selected->closer->name : '' }}</span>
                @endif
            </div>
        </div>

        {{-- Summary --}}
        @if($items->isNotEmpty())
        <div class="grid gap-4 sm:grid-cols-4">
            <div class="card p-5">
                <p class="eyebrow text-[10px]">Employees</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-slate-100">{{ $items->count() }}</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow text-[10px]">Total gross</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-slate-100 tabular-nums">₱{{ number_format($items->sum('gross_pay'), 2) }}</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow text-[10px]">Total deductions</p>
                <p class="mt-1 text-2xl font-bold text-accent-600 dark:text-accent-400 tabular-nums">₱{{ number_format($items->sum('total_deductions'), 2) }}</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow text-[10px]">Total net</p>
                <p class="mt-1 text-2xl font-bold text-brand-700 dark:text-brand-300 tabular-nums">₱{{ number_format($items->sum('net_pay'), 2) }}</p>
            </div>
        </div>
        @endif

        {{-- Where the money goes: earnings and deductions as percentages --}}
        @if($items->isNotEmpty())
            @php
                $gross = (float) $items->sum('gross_pay') ?: 1;
                $dedTotal = (float) $items->sum('total_deductions') ?: 1;
                $earn = ['Basic' => $items->sum('basic_pay'), 'Overtime' => $items->sum('overtime_pay'), 'Allowances' => $items->sum('allowances'), 'Holiday / night' => $items->sum('holiday_pay') + $items->sum('night_diff_pay')];
                $ded = ['SSS' => $items->sum('sss_deduction'), 'PhilHealth' => $items->sum('philhealth_deduction'), 'Pag-IBIG' => $items->sum('pagibig_deduction'), 'Tax' => $items->sum('withholding_tax'), 'Absences / half-day' => $items->sum('absences_deduction') + $items->sum('half_day_deduction'), 'Late / other' => $items->sum('late_undertime_deduction') + $items->sum('other_deductions')];
                $earnColors = ['bg-emerald-500', 'bg-emerald-400', 'bg-emerald-300', 'bg-emerald-200'];
                $dedColors = ['bg-accent-600', 'bg-accent-400', 'bg-amber-400', 'bg-rose-500', 'bg-rose-300', 'bg-slate-400'];
            @endphp
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="card p-5">
                    <div class="flex items-center justify-between text-sm"><span class="font-semibold text-emerald-600 dark:text-emerald-400">Earnings — {{ number_format(100 - ($items->sum('total_deductions') / $gross * 100), 1) }}% kept as net</span><span class="tabular-nums font-semibold">₱{{ number_format($items->sum('gross_pay'), 2) }}</span></div>
                    <div class="mt-2 flex h-2.5 overflow-hidden bg-gray-200 dark:bg-slate-700">
                        @foreach(array_values($earn) as $i => $v)<div class="{{ $earnColors[$i] }}" style="width: {{ $v / $gross * 100 }}%"></div>@endforeach
                    </div>
                    <ul class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-gray-700 dark:text-slate-200">
                        @foreach($earn as $label => $v)<li class="flex justify-between"><span>{{ $label }}</span><span class="tabular-nums">{{ number_format($v / $gross * 100, 1) }}%</span></li>@endforeach
                    </ul>
                </div>
                <div class="card p-5">
                    <div class="flex items-center justify-between text-sm"><span class="font-semibold text-accent-600 dark:text-accent-400">Deductions — {{ number_format($items->sum('total_deductions') / $gross * 100, 1) }}% of gross</span><span class="tabular-nums font-semibold">₱{{ number_format($items->sum('total_deductions'), 2) }}</span></div>
                    <div class="mt-2 flex h-2.5 overflow-hidden bg-gray-200 dark:bg-slate-700">
                        @foreach(array_values($ded) as $i => $v)<div class="{{ $dedColors[$i] }}" style="width: {{ $v / $gross * 100 }}%"></div>@endforeach
                    </div>
                    <ul class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-gray-700 dark:text-slate-200">
                        @foreach($ded as $label => $v)<li class="flex justify-between"><span>{{ $label }}</span><span class="tabular-nums">{{ number_format($v / $gross * 100, 1) }}% <span class="text-gray-400 dark:text-slate-500">· {{ number_format($v / $dedTotal * 100, 0) }}% of deductions</span></span></li>@endforeach
                    </ul>
                </div>
            </div>
        @endif

        {{-- Register --}}
        <div class="overflow-hidden card">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Employee</th>
                            <th class="px-4 py-3 text-right">Basic</th>
                            <th class="px-4 py-3 text-right">OT</th>
                            <th class="px-4 py-3 text-right">Allowances</th>
                            <th class="px-4 py-3 text-right">Gross</th>
                            <th class="px-4 py-3 text-right">Deductions</th>
                            <th class="px-4 py-3 text-right">Net pay</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700 tabular-nums">
                        @forelse($items as $item)
                            <tr>
                                <td class="cell-head px-4 py-3">
                                    <div class="font-medium text-gray-900 dark:text-slate-100">{{ $item->employee?->full_name }}</div>
                                    @if($item->isAdjusted())
                                        <span class="badge badge-warn mt-1" title="Edited by {{ $item->adjuster?->name }} · {{ $item->adjusted_at->format('M j, g:i A') }}{{ $item->remarks ? ' · '.$item->remarks : '' }}">Adjusted</span>
                                    @endif
                                </td>
                                <td data-label="Basic" class="px-4 py-3 text-right text-gray-700 dark:text-slate-200">{{ number_format($item->basic_pay, 2) }}</td>
                                <td data-label="OT" class="px-4 py-3 text-right text-gray-700 dark:text-slate-200">{{ number_format($item->overtime_pay, 2) }}</td>
                                <td data-label="Allowances" class="px-4 py-3 text-right text-gray-700 dark:text-slate-200">{{ number_format($item->allowances, 2) }}</td>
                                <td data-label="Gross" class="px-4 py-3 text-right text-gray-900 dark:text-slate-100">{{ number_format($item->gross_pay, 2) }}</td>
                                <td data-label="Deductions" class="px-4 py-3 text-right text-accent-600 dark:text-accent-400">{{ number_format($item->total_deductions, 2) }}</td>
                                <td data-label="Net pay" class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-slate-100">₱{{ number_format($item->net_pay, 2) }}</td>
                                <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                                    <div class="row-actions">
                                        <a href="{{ route('payroll.show', $item) }}">Payslip</a>
                                        @unless($selected->isClosed())
                                            <a href="{{ route('payroll.lines.edit', $item) }}" class="is-primary">Edit</a>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">
                                @if($selected)
                                    No payroll computed for this period yet — click <span class="font-medium">Run payroll</span>{{ $autoEnabled ? ', or wait for the automation after the cutoff ends' : '' }}.
                                @else
                                    No payroll periods yet — click <span class="font-medium">+ Next period</span>.
                                @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</x-app-layout>
