<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">My Payslips</h1>
    </x-slot>
    <x-slot name="back">{{ route('dashboard') }}</x-slot>
    <x-slot name="backLabel">Back to Dashboard</x-slot>

    <div class="page space-y-4">
        <p class="text-sm text-gray-600 dark:text-slate-300">Your payslips appear here as soon as the salary for that cutoff has been released. Open one to view or download it.</p>

        <div class="stat-strip">
            <x-stat label="Last net pay" :value="$summary['last'] ? '₱'.number_format($summary['last']->net_pay, 2) : '—'" :hint="$summary['last'] ? $summary['last']->payrollPeriod->label() : 'No payslip released yet'" :tone="$summary['last'] ? 'brand' : 'muted'" />
            <x-stat label="Paid this year" :value="'₱'.number_format($summary['ytd_net'], 2)" :hint="$summary['ytd_count'].' payslip(s) in '.now()->year" />
            <x-stat label="Paid by" :value="$summary['method']" :hint="$summary['method'] === 'Card' ? 'credited to your card' : 'envelope with printed payslip'" />
            <x-stat label="Next pay day" :value="$summary['next']->format('M j')" :hint="$summary['next']->format('l').' · '.($summary['next']->day === 15 ? '1st–15th cutoff' : '16th–end cutoff')" tone="success" />
        </div>

        <div class="overflow-hidden card">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Period</th>
                            <th class="px-4 py-3">Released</th>
                            <th class="px-4 py-3">Paid by</th>
                            <th class="px-4 py-3 text-right">Gross</th>
                            <th class="px-4 py-3 text-right">Deductions</th>
                            <th class="px-4 py-3 text-right">Net pay</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700 tabular-nums">
                        @forelse($items as $item)
                            <tr>
                                <td class="cell-head px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{{ $item->payrollPeriod->label() }}</td>
                                <td data-label="Released" class="px-4 py-3 text-gray-600 dark:text-slate-300 whitespace-nowrap">{{ $item->payrollPeriod->released_at->format('M j, Y') }} <span class="text-xs text-gray-400 dark:text-slate-500">· {{ $item->payrollPeriod->releaseTiming() }}</span></td>
                                <td data-label="Paid by" class="px-4 py-3"><span class="badge {{ $item->employee->paysByCard() ? 'badge-info' : 'badge-neutral' }}">{{ $item->employee->paysByCard() ? 'Card' : 'Cash' }}</span></td>
                                <td data-label="Gross" class="px-4 py-3 text-right text-gray-700 dark:text-slate-200">{{ number_format($item->gross_pay, 2) }}</td>
                                <td data-label="Deductions" class="px-4 py-3 text-right text-accent-600 dark:text-accent-400">{{ number_format($item->total_deductions, 2) }}</td>
                                <td data-label="Net pay" class="px-4 py-3 text-right font-semibold {{ $item->net_pay < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-slate-100' }}">{{ \App\Support\Money::peso($item->net_pay) }}</td>
                                <td data-label="Actions" class="px-4 py-3 whitespace-nowrap"><div class="row-actions"><a href="{{ route('payroll.show', $item) }}" class="is-primary">View / Download</a></div></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="p-0"><x-empty-state icon="cash" title="No released payslips yet" hint="Payslips show up here the moment the Super Admin releases a cutoff's payroll. Nothing is shown before the salary is actually out." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
