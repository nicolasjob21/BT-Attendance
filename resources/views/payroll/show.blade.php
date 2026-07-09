<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Payslip</h1>
    </x-slot>

    <div class="mx-auto max-w-2xl space-y-4">
        <div class="flex justify-between print:hidden">
            <a href="{{ url()->previous() }}" class="text-sm font-medium text-gray-600 dark:text-slate-300 hover:underline">&larr; Back</a>
            <button onclick="window.print()" class="rounded-xs border border-gray-300 dark:border-slate-600 px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700/60">Print / Save PDF</button>
        </div>

        <div class="card p-8">
            {{-- Header --}}
            <div class="flex items-start justify-between border-b border-gray-200 dark:border-slate-700 pb-5">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="grid h-9 w-9 place-items-center rounded-xs bg-linear-to-r from-brand-600 to-accent-500 font-bold text-white text-sm">BT</span>
                        <span class="text-lg font-bold text-gray-900 dark:text-slate-100">Brite TSI</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Payslip · Confidential</p>
                </div>
                <div class="text-right text-sm">
                    <p class="font-semibold text-gray-900 dark:text-slate-100">{{ $item->payrollPeriod->label() }}</p>
                    <p class="text-gray-500 dark:text-slate-400">{{ ucfirst(str_replace('_', ' ', $item->payrollPeriod->cutoff_type)) }} cutoff</p>
                </div>
            </div>

            {{-- Employee --}}
            <div class="grid grid-cols-2 gap-4 py-5 text-sm">
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-400 dark:text-slate-500">Employee</p>
                    <p class="font-medium text-gray-900 dark:text-slate-100">{{ $item->employee->full_name }}</p>
                    <p class="text-gray-500 dark:text-slate-400">{{ $item->employee->employee_no }}</p>
                </div>
                <div class="text-right">
                    <p class="text-xs uppercase tracking-wide text-gray-400 dark:text-slate-500">Type</p>
                    <p class="font-medium capitalize text-gray-900 dark:text-slate-100">{{ $item->employee->employee_type }}</p>
                </div>
            </div>

            {{-- Earnings / deductions --}}
            <div class="grid gap-6 border-t border-gray-200 dark:border-slate-700 pt-5 sm:grid-cols-2">
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Earnings</p>
                    <dl class="space-y-1.5 text-sm tabular-nums">
                        <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Basic pay</dt><dd class="text-gray-900 dark:text-slate-100">{{ number_format($item->basic_pay, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Overtime</dt><dd class="text-gray-900 dark:text-slate-100">{{ number_format($item->overtime_pay, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Holiday</dt><dd class="text-gray-900 dark:text-slate-100">{{ number_format($item->holiday_pay, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Night diff.</dt><dd class="text-gray-900 dark:text-slate-100">{{ number_format($item->night_diff_pay, 2) }}</dd></div>
                        <div class="flex justify-between border-t border-gray-100 dark:border-slate-700 pt-1.5 font-semibold"><dt>Gross pay</dt><dd>₱{{ number_format($item->gross_pay, 2) }}</dd></div>
                    </dl>
                </div>
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Deductions</p>
                    <dl class="space-y-1.5 text-sm tabular-nums">
                        <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">SSS</dt><dd class="text-rose-600">{{ number_format($item->sss_deduction, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">PhilHealth</dt><dd class="text-rose-600">{{ number_format($item->philhealth_deduction, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Pag-IBIG</dt><dd class="text-rose-600">{{ number_format($item->pagibig_deduction, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Withholding tax</dt><dd class="text-rose-600">{{ number_format($item->withholding_tax, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Late / absences</dt><dd class="text-rose-600">{{ number_format($item->late_undertime_deduction + $item->absences_deduction, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Half-day leave</dt><dd class="text-rose-600">{{ number_format($item->half_day_deduction, 2) }}</dd></div>
                        <div class="flex justify-between border-t border-gray-100 dark:border-slate-700 pt-1.5 font-semibold"><dt>Total deductions</dt><dd>₱{{ number_format($item->total_deductions, 2) }}</dd></div>
                    </dl>
                </div>
            </div>

            {{-- Net --}}
            <div class="mt-6 flex items-center justify-between rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-5 py-4 text-white shadow-xs">
                <span class="text-sm font-semibold uppercase tracking-wide text-white/90">Net pay</span>
                <span class="text-2xl font-bold tabular-nums">₱{{ number_format($item->net_pay, 2) }}</span>
            </div>

            <p class="mt-4 text-center text-xs text-gray-400 dark:text-slate-500">Computer-generated payslip · {{ now()->format('M j, Y g:i A') }}</p>
        </div>
    </div>
</x-app-layout>
