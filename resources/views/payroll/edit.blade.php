<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Edit Payroll Line</h1>
    </x-slot>
    <x-slot name="back">{{ route('payroll.index', ['period' => $period->id]) }}</x-slot>
    <x-slot name="backLabel">Back to Payroll</x-slot>

    @php
        $earn = ['basic_pay' => 'Basic pay', 'overtime_pay' => 'Overtime pay', 'night_diff_pay' => 'Night differential', 'holiday_pay' => 'Holiday pay', 'allowances' => 'Allowances'];
        $ded = ['late_undertime_deduction' => 'Late / undertime', 'absences_deduction' => 'Absences', 'half_day_deduction' => 'Half-day leave', 'sss_deduction' => 'SSS', 'philhealth_deduction' => 'PhilHealth', 'pagibig_deduction' => 'Pag-IBIG', 'withholding_tax' => 'Withholding tax', 'other_deductions' => 'Other deductions'];
        $vals = collect(array_merge($earn, $ded))->mapWithKeys(fn ($l, $k) => [$k => (float) old($k, $item->$k)]);
    @endphp

    <div class="page-form space-y-4"
         x-data="{ v: @js($vals), num(k) { return parseFloat(this.v[k]) || 0 },
                   get gross() { return {{ collect(array_keys($earn))->map(fn ($k) => "this.num('$k')")->implode(' + ') }} },
                   get ded() { return {{ collect(array_keys($ded))->map(fn ($k) => "this.num('$k')")->implode(' + ') }} },
                   get net() { return this.gross - this.ded },
                   peso(n) { return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) } }">

        <div class="card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-display text-xl font-bold text-gray-900 dark:text-slate-100">{{ $item->employee->full_name }}</h2>
                    <p class="text-sm text-gray-600 dark:text-slate-300">{{ $item->employee->employee_no }} · {{ $period->label() }} · pay date {{ $period->pay_date?->format('M j, Y') ?? '—' }} · monthly ₱{{ number_format($item->employee->monthly_salary, 2) }}</p>
                    @if($item->isAdjusted())
                        <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">Adjusted by {{ $item->adjuster?->name }} on {{ $item->adjusted_at->format('M j, g:i A') }}{{ $item->remarks ? ' — '.$item->remarks : '' }}</p>
                    @endif
                </div>
                <div class="text-right">
                    <p class="eyebrow text-[10px]">Net pay</p>
                    <p class="font-display text-3xl font-bold tabular-nums text-brand-700 dark:text-brand-300" x-text="peso(net)">₱{{ number_format($item->net_pay, 2) }}</p>
                </div>
            </div>
        </div>

        @if($period->isClosed())
            <div class="rounded-xs border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">This period is closed — the line is read-only.</div>
        @endif

        <form method="POST" action="{{ route('payroll.lines.update', $item) }}" class="grid gap-4 lg:grid-cols-2">
            @csrf @method('PUT')

            <section class="card p-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Earnings</h3>
                    <span class="text-sm font-semibold tabular-nums text-emerald-600 dark:text-emerald-400" x-text="peso(gross)"></span>
                </div>
                <div class="mt-3 grid gap-3">
                    @foreach($earn as $k => $label)
                        <div class="grid grid-cols-[1fr_160px] items-center gap-3">
                            <label for="{{ $k }}" class="text-sm text-gray-700 dark:text-slate-200">{{ $label }}</label>
                            <input type="number" step="0.01" min="0" id="{{ $k }}" name="{{ $k }}" x-model="v.{{ $k }}" @disabled($period->isClosed())
                                   class="w-full rounded-xs border-gray-300 text-right text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                            @error($k) <p class="col-span-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="card p-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Deductions</h3>
                    <span class="text-sm font-semibold tabular-nums text-accent-600 dark:text-accent-400" x-text="peso(ded)"></span>
                </div>
                <div class="mt-3 grid gap-3">
                    @foreach($ded as $k => $label)
                        <div class="grid grid-cols-[1fr_160px] items-center gap-3">
                            <label for="{{ $k }}" class="text-sm text-gray-700 dark:text-slate-200">{{ $label }}</label>
                            <input type="number" step="0.01" min="0" id="{{ $k }}" name="{{ $k }}" x-model="v.{{ $k }}" @disabled($period->isClosed())
                                   class="w-full rounded-xs border-gray-300 text-right text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                            @error($k) <p class="col-span-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="card p-5 lg:col-span-2">
                <label for="remarks" class="block text-sm font-medium text-gray-700 dark:text-slate-200">Remarks <span class="text-gray-400 dark:text-slate-500">(why this line was adjusted — shown on the register)</span></label>
                <input type="text" id="remarks" name="remarks" maxlength="500" value="{{ old('remarks', $item->remarks) }}" @disabled($period->isClosed())
                       class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600" placeholder="e.g. Site allowance for Sept · approved by CEO">
                @error('remarks') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror

                <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-slate-700">
                    <p class="text-xs text-gray-500 dark:text-slate-400">Gross, total deductions and net are recomputed from these fields on save. An adjusted line is skipped by automation and Recalculate.</p>
                    <div class="flex gap-2">
                        <a href="{{ route('payroll.index', ['period' => $period->id]) }}" class="btn-app btn-md btn-secondary">Cancel</a>
                        @unless($period->isClosed())
                            <button class="btn-app btn-md btn-brand">Save line</button>
                        @endunless
                    </div>
                </div>
            </section>
        </form>

        @if($item->isAdjusted() && ! $period->isClosed())
            <div class="flex justify-end">
                <x-confirm-action :action="route('payroll.lines.reset', $item)" title="Recompute this line?" size="md"
                    message="Your manual figures for {{ $item->employee->full_name }} are discarded and the line is recalculated from attendance, leave, OT and contribution rates." button="Discard edits & recompute" tone="amber" />
            </div>
        @endif
    </div>
</x-app-layout>
