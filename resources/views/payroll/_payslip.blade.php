{{-- One payslip. Used on the payslip page and on the batch print page. --}}
@php $p = $item->payrollPeriod; $e = $item->employee; @endphp
<div class="payslip card p-8">
    <div class="flex items-start justify-between border-b border-gray-200 dark:border-slate-700 pb-5">
        <div>
            <div class="flex items-center gap-2">
                <span class="grid h-9 w-9 place-items-center rounded-xs bg-brand-600 font-bold text-white text-sm">BT</span>
                <span class="text-lg font-bold text-gray-900 dark:text-slate-100">Brite TSI</span>
            </div>
            <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Payslip · Confidential</p>
        </div>
        <div class="text-right text-sm">
            <p class="font-semibold text-gray-900 dark:text-slate-100">{{ $p->label() }}</p>
            <p class="text-gray-500 dark:text-slate-400">{{ $p->cutoff_type === 'first_half' ? '1st–15th' : '16th–end' }} cutoff · pay date {{ $p->pay_date?->format('M j, Y') ?? '—' }}</p>
            @if($p->isReleased())
                <p class="text-xs text-emerald-600 dark:text-emerald-400">Released {{ $p->released_at->format('M j, Y') }} · {{ $p->releaseTiming() }}</p>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-3 gap-4 py-5 text-sm">
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-400 dark:text-slate-500">Employee</p>
            <p class="font-medium text-gray-900 dark:text-slate-100">{{ $e->full_name }}</p>
            <p class="text-gray-500 dark:text-slate-400">{{ $e->employee_no }}</p>
        </div>
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-400 dark:text-slate-500">Schedule</p>
            <p class="font-medium text-gray-900 dark:text-slate-100">{{ $e->schedule?->name ?? 'Office' }}</p>
        </div>
        <div class="text-right">
            <p class="text-xs uppercase tracking-wide text-gray-400 dark:text-slate-500">Paid by</p>
            <p class="font-medium text-gray-900 dark:text-slate-100">{{ $e->paysByCard() ? 'Card' : 'Cash' }}</p>
            @if($e->paysByCard() && $e->bank_account_no)<p class="text-gray-500 dark:text-slate-400">•••• {{ substr($e->bank_account_no, -4) }}</p>@endif
        </div>
    </div>

    <div class="grid gap-6 border-t border-gray-200 dark:border-slate-700 pt-5 sm:grid-cols-2">
        <div>
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Earnings</p>
            <dl class="space-y-1.5 text-sm tabular-nums">
                <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Basic pay</dt><dd class="text-gray-900 dark:text-slate-100">{{ number_format($item->basic_pay, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Overtime <span class="text-xs text-gray-400 dark:text-slate-500">({{ $p->overtimeWindowLabel() }})</span></dt><dd class="text-gray-900 dark:text-slate-100">{{ number_format($item->overtime_pay, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Allowances</dt><dd class="text-gray-900 dark:text-slate-100">{{ number_format($item->allowances, 2) }}</dd></div>
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
                <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Late / undertime</dt><dd class="text-rose-600">{{ number_format($item->late_undertime_deduction, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Absences</dt><dd class="text-rose-600">{{ number_format($item->absences_deduction, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Half-day leave</dt><dd class="text-rose-600">{{ number_format($item->half_day_deduction, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Other deductions</dt><dd class="text-rose-600">{{ number_format($item->other_deductions, 2) }}</dd></div>
                @php $installments = $item->deductionPayments; @endphp
                @foreach($installments->filter(fn ($pay) => $pay->deduction?->isLoan()) as $pay)
                    <div class="flex justify-between gap-3"><dt class="min-w-0 text-gray-600 dark:text-slate-300">Loan <span class="text-xs text-gray-400 dark:text-slate-500">· {{ $pay->deduction->description }} · balance after: ₱{{ number_format($pay->balanceAfter(), 2) }}</span></dt><dd class="text-rose-600">{{ number_format($pay->amount, 2) }}</dd></div>
                @endforeach
                @foreach($installments->filter(fn ($pay) => $pay->deduction && ! $pay->deduction->isLoan()) as $pay)
                    <div class="flex justify-between gap-3"><dt class="min-w-0 text-gray-600 dark:text-slate-300">Missing item <span class="text-xs text-gray-400 dark:text-slate-500">· {{ $pay->deduction->description }}{{ $pay->deduction->site ? ' ('.$pay->deduction->site->name.')' : '' }}</span></dt><dd class="text-rose-600">{{ number_format($pay->amount, 2) }}</dd></div>
                @endforeach
                @if($installments->isEmpty() && ((float) $item->loan_deduction > 0 || (float) $item->missing_item_deduction > 0))
                    <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Loan</dt><dd class="text-rose-600">{{ number_format($item->loan_deduction, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600 dark:text-slate-300">Missing item</dt><dd class="text-rose-600">{{ number_format($item->missing_item_deduction, 2) }}</dd></div>
                @endif
                <div class="flex justify-between border-t border-gray-100 dark:border-slate-700 pt-1.5 font-semibold"><dt>Total deductions</dt><dd>₱{{ number_format($item->total_deductions, 2) }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="mt-6 flex items-center justify-between rounded-xs bg-brand-700 px-5 py-4 text-white">
        <span class="text-sm font-semibold uppercase tracking-wide text-white/90">Net pay</span>
        <span class="text-2xl font-bold tabular-nums">{{ \App\Support\Money::peso($item->net_pay) }}</span>
    </div>
    @if($item->remarks)<p class="mt-3 text-xs text-gray-500 dark:text-slate-400">Note: {{ $item->remarks }}</p>@endif

    <p class="mt-4 text-center text-xs text-gray-400 dark:text-slate-500">Computer-generated payslip · {{ now()->format('M j, Y g:i A') }}</p>
</div>
