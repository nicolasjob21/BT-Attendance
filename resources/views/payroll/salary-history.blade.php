<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Salary History</h1>
    </x-slot>
    <x-slot name="back">{{ route('employees.index') }}</x-slot>
    <x-slot name="backLabel">Back to employees</x-slot>

    <div class="page space-y-5">

        <div class="flex justify-between print:hidden">
            <button onclick="window.print()" class="btn-app btn-md btn-secondary">Print / Save PDF</button>
        </div>

        {{-- Employee header --}}
        <div class="card p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xl font-bold text-gray-900 dark:text-slate-100">{{ $employee->full_name }}</p>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">
                        {{ $employee->employee_no ?? '—' }} · <span class="capitalize">{{ $employee->employee_type }}</span>
                        @if($employee->date_hired) · Hired {{ $employee->date_hired->format('M j, Y') }} @endif
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-slate-500">Current monthly salary</p>
                    <p class="text-xl font-bold text-gray-900 dark:text-slate-100 tabular-nums">₱{{ number_format($employee->monthly_salary, 2) }}</p>
                    <p class="text-xs text-gray-500 dark:text-slate-400 tabular-nums">Daily rate ₱{{ number_format($employee->daily_rate, 2) }}</p>
                </div>
            </div>
        </div>

        {{-- Lifetime summary --}}
        <div class="grid gap-4 sm:grid-cols-4">
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">Payroll periods</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-slate-100">{{ $items->count() }}</p>
            </div>
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">Total gross</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-slate-100 tabular-nums">₱{{ number_format($items->sum('gross_pay'), 2) }}</p>
            </div>
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">Total deductions</p>
                <p class="mt-1 text-2xl font-bold text-rose-600 tabular-nums">₱{{ number_format($items->sum('total_deductions'), 2) }}</p>
            </div>
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">Total net paid</p>
                <p class="mt-1 text-2xl font-bold text-brand-700 dark:text-brand-300 tabular-nums">₱{{ number_format($items->sum('net_pay'), 2) }}</p>
            </div>
        </div>

        {{-- History table --}}
        <div class="overflow-hidden card">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Period</th>
                            <th class="px-4 py-3">Cutoff</th>
                            <th class="px-4 py-3 text-right">Basic</th>
                            <th class="px-4 py-3 text-right">OT</th>
                            <th class="px-4 py-3 text-right">Gross</th>
                            <th class="px-4 py-3 text-right">SSS</th>
                            <th class="px-4 py-3 text-right">PhilHealth</th>
                            <th class="px-4 py-3 text-right">Pag-IBIG</th>
                            <th class="px-4 py-3 text-right">Deductions</th>
                            <th class="px-4 py-3 text-right">Net pay</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700 tabular-nums">
                        @forelse($items as $item)
                            <tr>
                                <td class="cell-head px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{{ $item->payrollPeriod?->label() ?? '—' }}</td>
                                <td data-label="Cutoff" class="px-4 py-3 capitalize text-gray-600 dark:text-slate-300">{{ $item->payrollPeriod ? str_replace('_', ' ', $item->payrollPeriod->cutoff_type) : '—' }}</td>
                                <td data-label="Basic" class="px-4 py-3 text-right text-gray-700 dark:text-slate-200">{{ number_format($item->basic_pay, 2) }}</td>
                                <td data-label="OT" class="px-4 py-3 text-right text-gray-700 dark:text-slate-200">{{ number_format($item->overtime_pay, 2) }}</td>
                                <td data-label="Gross" class="px-4 py-3 text-right text-gray-700 dark:text-slate-200">{{ number_format($item->gross_pay, 2) }}</td>
                                <td data-label="SSS" class="px-4 py-3 text-right text-rose-600">{{ number_format($item->sss_deduction, 2) }}</td>
                                <td data-label="PhilHealth" class="px-4 py-3 text-right text-rose-600">{{ number_format($item->philhealth_deduction, 2) }}</td>
                                <td data-label="Pag-IBIG" class="px-4 py-3 text-right text-rose-600">{{ number_format($item->pagibig_deduction, 2) }}</td>
                                <td data-label="Deductions" class="px-4 py-3 text-right text-rose-600">{{ number_format($item->total_deductions, 2) }}</td>
                                <td data-label="Net pay" class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-slate-100">₱{{ number_format($item->net_pay, 2) }}</td>
                                <td data-label="" class="px-4 py-3 text-right">
                                    <a href="{{ route('payroll.show', $item) }}" class="text-sm font-medium text-brand-700 dark:text-brand-300 hover:underline">Payslip</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="11" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">No payroll has been generated for this employee yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</x-app-layout>
