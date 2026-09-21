<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Payroll</h1>
    </x-slot>
    <x-slot name="back">{{ route('dashboard') }}</x-slot>
    <x-slot name="backLabel">Back to Dashboard</x-slot>

    <div class="page space-y-5">

        {{-- Pay day: the fixed schedule and what needs doing right now --}}
        @php
            $payTone = ['overdue' => 'danger', 'due' => 'warn', 'computed' => 'info', 'upcoming' => 'neutral'][$payDay['state']];
            $payBorder = ['overdue' => 'border-l-rose-500', 'due' => 'border-l-amber-500', 'computed' => 'border-l-brand-500', 'upcoming' => 'border-l-gray-300 dark:border-l-slate-600'][$payDay['state']];
        @endphp
        <div class="card flex flex-wrap items-center gap-x-6 gap-y-3 border-l-4 {{ $payBorder }} px-5 py-4">
            <div class="flex items-center gap-3">
                <p class="eyebrow">Pay day</p>
                <span class="badge badge-{{ $payTone }}">@if($payDay['state'] !== 'upcoming')<i class="dot {{ $payDay['state'] === 'due' ? 'animate-pulse' : '' }}"></i>@endif{{ $payDay['title'] }}</span>
            </div>
            <p class="basis-full text-sm text-gray-700 dark:text-slate-200 sm:min-w-0 sm:flex-1 sm:basis-auto">{{ $payDay['message'] }}</p>
            @if($payDay['action'] === 'run' && $payDay['period'] && ! $payDay['period']->isClosed())
                <form method="POST" action="{{ route('payroll.generate', $payDay['period']) }}" class="basis-full sm:ml-auto sm:basis-auto">@csrf<button class="btn-app btn-sm btn-brand w-full sm:w-auto">Run payroll</button></form>
            @elseif($payDay['action'] === 'release' && $payDay['period'] && (! $selected || $selected->id !== $payDay['period']->id))
                <a href="{{ route('payroll.index', ['period' => $payDay['period']->id]) }}" class="btn-app btn-sm btn-brand basis-full sm:ml-auto sm:basis-auto">Open {{ $payDay['period']->period_start->format('M j') }} – {{ $payDay['period']->period_end->format('M j') }}</a>
            @endif
            <dl class="flex basis-full flex-wrap items-center gap-x-5 gap-y-1.5 text-xs text-gray-500 dark:text-slate-400">
                <div class="flex items-center gap-2"><dt class="badge badge-muted">1st – 15th</dt><dd>paid on the <b>15th</b></dd></div>
                <div class="flex items-center gap-2"><dt class="badge badge-muted">16th – last day</dt><dd>paid on the <b>last day of the month</b></dd></div>
                <div>Everyone who runs payroll gets a reminder on the morning of pay day.</div>
            </dl>
        </div>

        {{-- Period: which cutoff, its state, and what to do next --}}
        <div class="card p-4">
            <div class="flex flex-wrap items-center gap-3">
                <form method="GET" action="{{ route('payroll.index') }}" class="flex min-w-0 flex-wrap items-center gap-2">
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
                        @if($selected->isReleased())
                            <span class="badge badge-success"><i class="dot"></i>Released</span>
                            <span class="text-xs text-gray-500 dark:text-slate-400">{{ $selected->released_at->format('M j, g:i A') }} · {{ $selected->releaseTiming() }}{{ $selected->releaser ? ' · by '.$selected->releaser->name : '' }}</span>
                        @else
                            <x-status-badge :status="$selected->status" />
                        @endif
                        @if($selected->generated_at && ! $selected->isReleased())
                            <span class="text-xs text-gray-500 dark:text-slate-400">computed {{ $selected->generated_at->format('M j, g:i A') }} · {{ $selected->generated_by === 'auto' ? 'automatically' : 'manually' }}</span>
                        @endif
                    @endif
                </form>

                {{-- Primary actions for this period --}}
                <div class="grid basis-full grid-cols-2 gap-2 sm:ml-auto sm:flex sm:basis-auto sm:flex-wrap sm:items-center">
                    @if($selected && ! $selected->isReleased())
                        @unless($selected->isClosed())
                            <form method="POST" action="{{ route('payroll.generate', $selected) }}" class="contents sm:block"
                                  onsubmit="return confirm('Compute payroll for all active employees in this period? Manually adjusted lines are kept.')">
                                @csrf
                                <button class="btn-app btn-md w-full sm:w-auto {{ $items->isEmpty() ? 'btn-brand' : 'btn-secondary' }}">{{ $items->isEmpty() ? 'Run payroll' : 'Recalculate' }}</button>
                            </form>
                        @endunless
                        @if($canEdit && $items->isNotEmpty())
                            <x-confirm-action :action="route('payroll.release', $selected)" title="Release this payroll?" size="md" variant="primary" class="w-full sm:w-auto"
                                message="Confirm that salaries for {{ $selected->label() }} have been paid out — {{ $byCard }} by card, {{ $items->count() - $byCard }} in cash. The figures become final and every employee will see and can download their payslip immediately." button="Release payroll" />
                        @endif
                    @endif
                </div>
            </div>

            {{-- Tools --}}
            <div class="mt-3 grid grid-cols-2 gap-2 border-t border-gray-100 pt-3 sm:flex sm:flex-wrap sm:items-center dark:border-slate-700">
                @if($selected && $items->isNotEmpty())
                    {{-- Print one employee's payslip: type a name or employee no., pick, print. --}}
                    <div x-data="payslipSearch(@js($items->map(fn ($i) => [
                            'id' => $i->id,
                            'name' => $i->employee?->full_name ?? '—',
                            'no' => $i->employee?->employee_no ?? '',
                            'method' => $i->employee?->paysByCard() ? 'Card' : 'Cash',
                            'url' => route('payroll.print', [$selected, 'employee' => $i->employee_id]),
                        ])->values()))" class="relative col-span-2 sm:col-span-1" @keydown.escape="close()">
                        <label class="relative block">
                            <span class="sr-only">Print an employee's payslip</span>
                            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
                            <input type="search" x-model="q" @focus="open = true" @input="open = true; cursor = 0" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="pick()"
                                   placeholder="Print payslip: search employee…" autocomplete="off"
                                   class="w-full rounded-xs border-gray-300 py-2 pl-9 pr-3 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-900/40 sm:w-72">
                        </label>
                        <div x-show="open" x-cloak @click.outside="close()" class="absolute left-0 z-20 mt-1 w-full overflow-hidden sm:w-80 rounded-xs border border-gray-200 bg-white text-sm shadow-lg dark:border-slate-700 dark:bg-slate-800">
                            <ul class="max-h-64 overflow-y-auto py-1" role="listbox">
                                <template x-for="(e, i) in matches()" :key="e.id">
                                    <li>
                                        <a :href="e.url" target="_blank" @click="close()" @mouseenter="cursor = i"
                                           class="flex items-center justify-between gap-3 px-4 py-2 text-gray-700 dark:text-slate-200"
                                           :class="cursor === i ? 'bg-brand-50 dark:bg-brand-500/15' : ''" role="option">
                                            <span class="min-w-0">
                                                <span class="block truncate font-medium text-gray-900 dark:text-slate-100" x-text="e.name"></span>
                                                <span class="block text-xs text-gray-500 dark:text-slate-400" x-text="e.no"></span>
                                            </span>
                                            <span class="badge" :class="e.method === 'Card' ? 'badge-info' : 'badge-neutral'" x-text="e.method"></span>
                                        </a>
                                    </li>
                                </template>
                                <li x-show="matches().length === 0" class="px-4 py-3 text-gray-400 dark:text-slate-500">No employee matches "<span x-text="q"></span>" in this period.</li>
                            </ul>
                            <div class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500 dark:border-slate-700 dark:text-slate-400">
                                Print a batch instead:
                                <a href="{{ route('payroll.print', [$selected, 'method' => 'cash']) }}" target="_blank" class="font-medium text-brand-700 hover:underline dark:text-brand-300">cash ({{ $items->count() - $byCard }})</a> ·
                                <a href="{{ route('payroll.print', [$selected, 'method' => 'card']) }}" target="_blank" class="font-medium text-brand-700 hover:underline dark:text-brand-300">card ({{ $byCard }})</a> ·
                                <a href="{{ route('payroll.print', $selected) }}" target="_blank" class="font-medium text-brand-700 hover:underline dark:text-brand-300">everyone ({{ $items->count() }})</a>
                            </div>
                        </div>
                    </div>
                    <a href="{{ route('payroll.export', $selected) }}" class="btn-app btn-md btn-secondary w-full sm:w-auto">Download XLSX</a>
                @endif
                @can('manage payroll rates')
                    <a href="{{ route('payroll.rates') }}" class="btn-app btn-md btn-secondary w-full sm:w-auto">Payroll rates</a>
                @endcan
                <a href="{{ route('payroll.deductions') }}" class="btn-app btn-md btn-secondary col-span-2 w-full sm:col-auto sm:w-auto">Loans &amp; missing items</a>
                <form method="POST" action="{{ route('payroll.periods.create') }}" class="col-span-2 sm:col-auto sm:ml-auto">
                    @csrf
                    <button class="btn-app btn-md btn-secondary w-full sm:w-auto">+ Next period</button>
                </form>
            </div>
        </div>

        {{-- Summary --}}
        @if($items->isNotEmpty())
        <div class="stat-strip">
            <x-stat label="Employees" :value="$items->count()" :hint="$byCard.' by card · '.($items->count() - $byCard).' in cash'" />
            <x-stat label="Total gross" :value="'₱'.number_format($items->sum('gross_pay'), 2)" />
            <x-stat label="Total deductions" :value="'₱'.number_format($items->sum('total_deductions'), 2)" tone="danger" />
            <x-stat label="Total net" :value="'₱'.number_format($items->sum('net_pay'), 2)" hint="what actually goes out" tone="brand" />
        </div>
        @endif

        {{-- Where the money goes: earnings and deductions as percentages --}}
        @if($items->isNotEmpty())
            @php
                $gross = (float) $items->sum('gross_pay') ?: 1;
                $dedTotal = (float) $items->sum('total_deductions') ?: 1;
                $earn = ['Basic' => $items->sum('basic_pay'), 'Overtime' => $items->sum('overtime_pay'), 'Allowances' => $items->sum('allowances'), 'Holiday / night' => $items->sum('holiday_pay') + $items->sum('night_diff_pay')];
                $ded = ['SSS' => $items->sum('sss_deduction'), 'PhilHealth' => $items->sum('philhealth_deduction'), 'Pag-IBIG' => $items->sum('pagibig_deduction'), 'Tax' => $items->sum('withholding_tax'), 'Absences / half-day' => $items->sum('absences_deduction') + $items->sum('half_day_deduction'), 'Late / other' => $items->sum('late_undertime_deduction') + $items->sum('other_deductions'), 'Loans' => $items->sum('loan_deduction'), 'Missing items' => $items->sum('missing_item_deduction')];
                $earnColors = ['bg-emerald-500', 'bg-emerald-400', 'bg-emerald-300', 'bg-emerald-200'];
                $dedColors = ['bg-accent-600', 'bg-accent-400', 'bg-amber-400', 'bg-rose-500', 'bg-rose-300', 'bg-slate-400', 'bg-sky-500', 'bg-violet-500'];
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
                    <ul class="mt-2 grid gap-x-4 gap-y-1 text-xs text-gray-700 sm:grid-cols-2 dark:text-slate-200">
                        @foreach($ded as $label => $v)<li class="flex justify-between gap-2"><span>{{ $label }}</span><span class="tabular-nums whitespace-nowrap">{{ number_format($v / $gross * 100, 1) }}% <span class="text-gray-400 dark:text-slate-500">· {{ number_format($v / $dedTotal * 100, 0) }}% of ded.</span></span></li>@endforeach
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
                            <th class="px-4 py-3">Payout</th>
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
                                <td data-label="Payout" class="px-4 py-3"><span class="badge {{ $item->employee?->paysByCard() ? 'badge-info' : 'badge-neutral' }}">{{ $item->employee?->paysByCard() ? 'Card' : 'Cash' }}</span></td>
                                <td data-label="Basic" class="px-4 py-3 text-right text-gray-700 dark:text-slate-200">{{ number_format($item->basic_pay, 2) }}</td>
                                <td data-label="OT" class="px-4 py-3 text-right text-gray-700 dark:text-slate-200">{{ number_format($item->overtime_pay, 2) }}</td>
                                <td data-label="Allowances" class="px-4 py-3 text-right text-gray-700 dark:text-slate-200">{{ number_format($item->allowances, 2) }}</td>
                                <td data-label="Gross" class="px-4 py-3 text-right text-gray-900 dark:text-slate-100">{{ number_format($item->gross_pay, 2) }}</td>
                                <td data-label="Deductions" class="px-4 py-3 text-right text-accent-600 dark:text-accent-400">{{ number_format($item->total_deductions, 2) }}</td>
                                <td data-label="Net pay" class="px-4 py-3 text-right font-semibold {{ $item->net_pay < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-slate-100' }}">{{ \App\Support\Money::peso($item->net_pay) }}</td>
                                <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                                    <div class="row-actions">
                                        <a href="{{ route('payroll.show', $item) }}">Payslip</a>
                                        @if($canEdit && ! $selected->isClosed())
                                            <a href="{{ route('payroll.lines.edit', $item) }}" class="is-primary">Edit</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">
                                @if($selected)
                                    No payroll computed for this period yet — click <span class="font-medium">Run payroll</span>.
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
    <script>
        function payslipSearch(items) {
            return {
                items, q: '', open: false, cursor: 0,
                matches() {
                    const q = this.q.trim().toLowerCase();
                    const words = q.split(/\s+/).filter(Boolean); const list = words.length ? this.items.filter(e => { const hay = (e.name + ' ' + e.no).toLowerCase(); return words.every(w => hay.includes(w)); }) : this.items;
                    return list.slice(0, 8);
                },
                move(d) { const n = this.matches().length; if (!n) return; this.open = true; this.cursor = (this.cursor + d + n) % n; },
                pick() { const e = this.matches()[this.cursor]; if (e) { window.open(e.url, '_blank'); this.close(); } },
                close() { this.open = false; },
            };
        }
    </script>
</x-app-layout>
