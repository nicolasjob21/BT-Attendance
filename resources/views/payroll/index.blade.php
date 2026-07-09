<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Payroll</h1>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-5">

        {{-- Period selector --}}
        <div class="flex flex-wrap items-center justify-between gap-3 card p-4">
            <form method="GET" action="{{ route('payroll.index') }}" class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                <label for="period" class="text-sm text-gray-600 dark:text-slate-300">Period</label>
                <select id="period" name="period" onchange="this.form.submit()"
                        class="w-full min-w-0 max-w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500 sm:w-auto">
                    @foreach($periods as $p)
                        <option value="{{ $p->id }}" @selected($selected && $selected->id === $p->id)>
                            {{ $p->label() }} ({{ ucfirst(str_replace('_', ' ', $p->cutoff_type)) }})
                        </option>
                    @endforeach
                </select>
                @if($selected)<x-status-badge :status="$selected->status" />@endif
            </form>

            @if($selected)
            <form method="POST" action="{{ route('payroll.generate', $selected) }}"
                  onsubmit="return confirm('Compute payroll for all active employees in this period? Existing lines will be recalculated.')">
                @csrf
                <button class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-4 py-2 text-sm font-semibold text-white hover:from-brand-700 hover:to-accent-600">
                    {{ $items->isEmpty() ? 'Run payroll' : 'Recalculate' }}
                </button>
            </form>
            @endif
        </div>

        {{-- Summary --}}
        @if($items->isNotEmpty())
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">Employees</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-slate-100">{{ $items->count() }}</p>
            </div>
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">Total gross</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-slate-100 tabular-nums">₱{{ number_format($items->sum('gross_pay'), 2) }}</p>
            </div>
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">Total net</p>
                <p class="mt-1 text-2xl font-bold text-brand-700 dark:text-brand-300 tabular-nums">₱{{ number_format($items->sum('net_pay'), 2) }}</p>
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
                            <th class="px-4 py-3 text-right">SSS</th>
                            <th class="px-4 py-3 text-right">PhilHealth</th>
                            <th class="px-4 py-3 text-right">Pag-IBIG</th>
                            <th class="px-4 py-3 text-right">Net pay</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700 tabular-nums">
                        @forelse($items as $item)
                            <tr>
                                <td class="cell-head px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{{ $item->employee?->full_name }}</td>
                                <td data-label="Basic" class="px-4 py-3 text-right text-gray-700 dark:text-slate-200">{{ number_format($item->basic_pay, 2) }}</td>
                                <td data-label="OT" class="px-4 py-3 text-right text-gray-700 dark:text-slate-200">{{ number_format($item->overtime_pay, 2) }}</td>
                                <td data-label="SSS" class="px-4 py-3 text-right text-rose-600">{{ number_format($item->sss_deduction, 2) }}</td>
                                <td data-label="PhilHealth" class="px-4 py-3 text-right text-rose-600">{{ number_format($item->philhealth_deduction, 2) }}</td>
                                <td data-label="Pag-IBIG" class="px-4 py-3 text-right text-rose-600">{{ number_format($item->pagibig_deduction, 2) }}</td>
                                <td data-label="Net pay" class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-slate-100">₱{{ number_format($item->net_pay, 2) }}</td>
                                <td data-label="" class="px-4 py-3 text-right">
                                    <a href="{{ route('payroll.show', $item) }}" class="text-sm font-medium text-brand-700 dark:text-brand-300 hover:underline">Payslip</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">No payroll computed for this period yet — click <span class="font-medium">Run payroll</span>.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</x-app-layout>
