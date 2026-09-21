@use('App\Models\PayrollDeduction')
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Loans &amp; Missing Items</h1>
    </x-slot>
    <x-slot name="back">{{ route('payroll.index') }}</x-slot>
    <x-slot name="backLabel">Back to Payroll</x-slot>

    <div class="page space-y-5">
        @if(session('status'))
            <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-xs border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-900/30 dark:text-rose-200">{{ $errors->first() }}</div>
        @endif

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" x-data>
            <p class="max-w-3xl text-sm text-gray-500 dark:text-slate-400">
                Payroll takes one installment per cutoff until the balance is zero, and each installment shows on the payslip. A <b>loan</b> is between the company and one employee. A <b>missing item</b> is charged to whoever was responsible — one employee or several, split evenly.
            </p>
            @if($canManage)
                <button type="button" @click="$dispatch('open-deduction')" class="btn-app btn-md btn-brand shrink-0">+ Add loan or missing item</button>
            @endif
        </div>

        <div class="stat-strip">
            <x-stat label="Active loans" :value="$stats['loans']" :hint="'₱'.number_format($stats['loan_balance'], 2).' still to collect'" :tone="$stats['loans'] ? 'brand' : 'muted'" />
            <x-stat label="Missing-item charges" :value="$stats['missing']" :hint="'₱'.number_format($stats['missing_balance'], 2).' still to collect'" :tone="$stats['missing'] ? 'danger' : 'muted'" />
            <x-stat label="Next cutoff takes" :value="'₱'.number_format($stats['next_cutoff'], 2)" :hint="'pay day '.$nextCutoff->format('M j')" tone="warn" />
            <x-stat label="Outstanding" :value="'₱'.number_format($stats['loan_balance'] + $stats['missing_balance'], 2)" hint="all active balances" />
        </div>

        <div>
            {{-- List --}}
            <section class="card overflow-hidden">
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                    @foreach([null => 'All', 'loan' => 'Loans', 'missing_item' => 'Missing items'] as $t => $label)
                        <a href="{{ route('payroll.deductions', array_filter(['type' => $t, 'status' => $status])) }}" class="btn-app btn-xs {{ $type === $t ? 'btn-brand' : 'btn-secondary' }}">{{ $label }}</a>
                    @endforeach
                    <span class="mx-1 text-gray-300 dark:text-slate-600">|</span>
                    @foreach(['active' => 'Active', 'paid' => 'Paid off', 'cancelled' => 'Cancelled'] as $st => $label)
                        <a href="{{ route('payroll.deductions', array_filter(['type' => $type, 'status' => $st])) }}" class="btn-app btn-xs {{ $status === $st ? 'btn-dark' : 'btn-secondary' }}">{{ $label }}</a>
                    @endforeach
                </div>
                <div class="overflow-x-auto">
                    <table class="table-stack min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700">
                        <thead class="whitespace-nowrap bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-slate-800/60 dark:text-slate-400">
                            <tr>
                                <th class="px-4 py-3">Employee</th>
                                <th class="px-4 py-3">What</th>
                                <th class="px-4 py-3 text-right">Amount</th>
                                <th class="px-4 py-3 text-right">Balance</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700" x-data="{ open: null }">
                            @forelse($deductions as $d)
                                @php $pct = (float) $d->total_amount > 0 ? (int) round($d->paid() / (float) $d->total_amount * 100) : 0; @endphp
                                <tr>
                                    <td class="cell-head px-4 py-3">
                                        <div class="font-medium text-gray-900 dark:text-slate-100">{{ $d->employee?->full_name }}</div>
                                        <div class="text-xs text-gray-500 dark:text-slate-400">{{ $d->employee?->employee_no }}</div>
                                    </td>
                                    <td data-label="What" class="min-w-[16rem] px-4 py-3">
                                      <div class="min-w-0 max-sm:text-right">
                                        <div class="flex items-center gap-2 max-sm:justify-end">
                                            <span class="badge {{ $d->isLoan() ? 'badge-info' : 'badge-danger' }}">{{ $d->typeLabel() }}</span>
                                            <span class="text-gray-900 dark:text-slate-100">{{ $d->description }}</span>
                                        </div>
                                        <div class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">
                                            @if($d->site) {{ $d->site->name }} · @endif
                                            @if($d->incident_date) {{ $d->incident_date->format('M j, Y') }} · @endif
                                            from {{ $d->starts_on->format('M j, Y') }}
                                            @if($d->status === 'active') · {{ $d->cutoffsLeft() }} cutoff(s) left @endif
                                            @if($d->remarks) · <span title="{{ $d->remarks }}">note</span> @endif
                                        </div>
                                      </div>
                                    </td>
                                    <td data-label="Amount" class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                                      <div>
                                        <div class="font-medium text-gray-900 dark:text-slate-100">{{ number_format($d->total_amount, 2) }}</div>
                                        <div class="text-xs text-gray-500 dark:text-slate-400">{{ number_format($d->installment_amount, 2) }} / cutoff</div>
                                      </div>
                                    </td>
                                    <td data-label="Balance" class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                                      <div>
                                        <div class="font-semibold {{ (float) $d->balance > 0 && $d->status === 'active' ? 'text-accent-700 dark:text-accent-300' : 'text-gray-400 dark:text-slate-500' }}">
                                            {{ number_format($d->balance, 2) }}
                                            @if($d->status !== 'active')<span class="badge {{ $d->status === 'paid' ? 'badge-success' : 'badge-muted' }} ml-1 capitalize">{{ $d->status }}</span>@endif
                                        </div>
                                        <div class="mt-1 flex items-center justify-end gap-2 text-xs text-gray-500 dark:text-slate-400">
                                            <span class="hidden h-1 w-16 overflow-hidden bg-gray-200 sm:block dark:bg-slate-700"><span class="block h-full bg-emerald-500" style="width: {{ $pct }}%"></span></span>
                                            {{ number_format($d->paid(), 2) }} paid
                                        </div>
                                      </div>
                                    </td>
                                    <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                                        @if($canManage && $d->status === 'active')
                                            <div class="row-actions justify-end">
                                                <button type="button" @click="open = open === {{ $d->id }} ? null : {{ $d->id }}" class="is-primary">Edit</button>
                                                <x-confirm-action :action="route('payroll.deductions.cancel', $d)" tone="rose" size="xs" button="Cancel" reason
                                                    title="Stop collecting this balance?" message="₱{{ number_format($d->balance, 2) }} will not be deducted. Installments already taken stay on the payslips they appeared on." />
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                                @if($canManage && $d->status === 'active')
                                    <tr x-show="open === {{ $d->id }}" x-cloak class="bg-gray-50/60 dark:bg-slate-800/40">
                                        <td colspan="5" class="px-4 py-3">
                                            <form method="POST" action="{{ route('payroll.deductions.update', $d) }}" class="grid gap-3 sm:grid-cols-[10rem_11rem_1fr_auto] sm:items-end">
                                                @csrf @method('PUT')
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-200">Per cutoff (₱)</label>
                                                    <input type="number" step="0.01" min="0.01" name="installment_amount" value="{{ $d->installment_amount }}" required class="mt-1 w-full rounded-xs border-gray-300 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-200">Collect from</label>
                                                    <input type="date" name="starts_on" value="{{ $d->starts_on->toDateString() }}" required class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600 dark:[color-scheme:dark]">
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-700 dark:text-slate-200">Remarks</label>
                                                    <input type="text" name="remarks" value="{{ $d->remarks }}" class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                                </div>
                                                <div class="flex gap-2">
                                                    <button type="button" @click="open = null" class="btn-app btn-sm btn-secondary">Close</button>
                                                    <button class="btn-app btn-sm btn-brand">Save</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr><td colspan="5" class="p-0"><x-empty-state icon="cash" :title="$status === 'active' ? 'No active balances' : 'Nothing here'" :hint="$status === 'active' ? ($canManage ? 'Click “Add loan or missing item”; payroll takes one installment per cutoff automatically.' : 'The Super Admin records loans and missing-item charges; payroll takes one installment per cutoff automatically.') : null" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($deductions->hasPages())
                    <div class="border-t border-gray-100 px-4 py-3 dark:border-slate-700">{{ $deductions->links() }}</div>
                @endif
            </section>

            {{-- Add form (Super Admin) --}}
            @if($canManage)
                <div x-data="{ open: @js($errors->any()) }" @open-deduction.window="open = true" @keydown.escape.window="open = false">
                    <template x-teleport="body">
                    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center">
                    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="open = false"></div>
                    <form x-show="open" x-transition method="POST" action="{{ route('payroll.deductions.store') }}"
                          class="relative max-h-[92vh] w-full max-w-lg space-y-4 overflow-y-auto rounded-xs border border-gray-200 bg-white p-5 shadow-2xl dark:border-hair dark:bg-surface"
                          x-data="deductionForm({ type: @js(old('type', 'loan')), total: @js(old('total_amount', '')), cutoffs: @js(old('cutoffs', 1)), people: @js(collect(old('employees', []))->map(fn ($v) => (int) $v)->all()) })">
                        @csrf
                        <div>
                            <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Add a deduction</h2>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">Payroll will take it back one installment per cutoff.</p>
                        </div>

                        {{-- 1. What --}}
                        <div>
                            <label for="ded_type" class="block text-sm font-medium text-gray-700 dark:text-slate-200">What is this?</label>
                            <select id="ded_type" name="type" x-model="type" class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                <option value="loan">Loan — one employee</option>
                                <option value="missing_item">Missing item — one or more employees</option>
                            </select>
                        </div>

                        {{-- 2. Who --}}
                        <div x-show="type === 'loan'">
                            <label for="ded_employee" class="block text-sm font-medium text-gray-700 dark:text-slate-200">Employee</label>
                            <select id="ded_employee" name="employee_id" :disabled="type !== 'loan'" required class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                <option value="">Select an employee…</option>
                                @foreach($employees as $e)
                                    <option value="{{ $e->id }}" @selected(old('employee_id') == $e->id)>{{ $e->full_name }} · {{ $e->employee_no }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div x-show="type === 'missing_item'" x-cloak class="space-y-3">
                            <div>
                                <label for="ded_site" class="block text-sm font-medium text-gray-700 dark:text-slate-200">Site where it went missing</label>
                                <select id="ded_site" name="site_id" :disabled="type !== 'missing_item'" required class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                    <option value="">Select a site…</option>
                                    @foreach($sites as $s)
                                        <option value="{{ $s->id }}" @selected(old('site_id') == $s->id)>{{ $s->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <div class="flex items-center justify-between">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-200">Employees responsible <span class="badge ml-1" :class="people.length ? 'badge-info' : 'badge-muted'" x-text="people.length + ' selected'"></span></label>
                                    <button type="button" x-show="people.length" @click="people = []" class="text-xs text-gray-500 hover:underline dark:text-slate-400">Clear</button>
                                </div>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">Tick one or more. The cost is split evenly between them.</p>
                                <input type="search" x-model="search" placeholder="Find by name or no.…" class="mt-1.5 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                <div class="mt-2 max-h-44 overflow-y-auto rounded-xs border border-gray-200 dark:border-slate-700">
                                    @foreach($employees as $e)
                                        <label class="flex cursor-pointer items-center gap-3 border-b border-gray-100 px-3 py-1.5 text-sm last:border-0 hover:bg-gray-50 dark:border-slate-700/60 dark:hover:bg-slate-800/40"
                                               x-show="matches(@js(strtolower($e->first_name.' '.$e->last_name.' '.$e->employee_no)))">
                                            <input type="checkbox" name="employees[]" value="{{ $e->id }}" :disabled="type !== 'missing_item'" x-model.number="people" class="rounded-xs border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600">
                                            <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-slate-100">{{ $e->first_name }} {{ $e->last_name }}</span>
                                            <span class="text-xs text-gray-500 dark:text-slate-400">{{ $e->employee_no }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        {{-- 3. What for / how much --}}
                        <div>
                            <label for="ded_description" class="block text-sm font-medium text-gray-700 dark:text-slate-200" x-text="type === 'loan' ? 'What is the loan for?' : 'What went missing?'"></label>
                            <input id="ded_description" type="text" name="description" value="{{ old('description') }}" required :placeholder="type === 'loan' ? 'e.g. Cash advance — medical' : 'e.g. 2 × cordless drill, 1 × ladder'" class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="ded_total" class="block text-sm font-medium text-gray-700 dark:text-slate-200" x-text="type === 'loan' ? 'Amount (₱)' : 'Total cost (₱)'"></label>
                                <input id="ded_total" type="number" step="0.01" min="0.01" name="total_amount" x-model="total" required class="mt-1 w-full rounded-xs border-gray-300 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                            </div>
                            <div>
                                <label for="ded_cutoffs" class="block text-sm font-medium text-gray-700 dark:text-slate-200">Pay back over</label>
                                <div class="mt-1 flex items-center gap-2">
                                    <input id="ded_cutoffs" type="number" min="1" max="120" name="cutoffs" x-model="cutoffs" required class="w-full rounded-xs border-gray-300 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                                    <span class="shrink-0 text-sm text-gray-500 dark:text-slate-400">cutoff(s)</span>
                                </div>
                            </div>
                        </div>
                        <p class="rounded-xs bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-slate-800/60 dark:text-slate-300" x-show="perCutoff() > 0" x-cloak>
                            <template x-if="type === 'loan'">
                                <span><b class="text-gray-900 dark:text-slate-100" x-text="money(perCutoff())"></b> per cutoff · <span x-text="months()"></span></span>
                            </template>
                            <template x-if="type === 'missing_item'">
                                <span x-show="people.length">Each of the <b class="text-gray-900 dark:text-slate-100" x-text="people.length"></b> pays <b class="text-gray-900 dark:text-slate-100" x-text="money(perPerson())"></b> — <b class="text-gray-900 dark:text-slate-100" x-text="money(perPerson() / Math.max(1, parseInt(cutoffs) || 1))"></b> per cutoff · <span x-text="months()"></span></span>
                            </template>
                        </p>

                        {{-- 4. When --}}
                        <div>
                            <label for="ded_starts" class="block text-sm font-medium text-gray-700 dark:text-slate-200">Start collecting from</label>
                            <input id="ded_starts" type="date" name="starts_on" value="{{ old('starts_on', now()->toDateString()) }}" required class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600 dark:[color-scheme:dark]">
                            <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">First installment comes out on the pay day on or after this date (next: {{ $nextCutoff->format('M j') }}).</p>
                        </div>
                        <div>
                            <label for="ded_remarks" class="block text-sm font-medium text-gray-700 dark:text-slate-200">Remarks <span class="text-gray-400">(optional)</span></label>
                            <input id="ded_remarks" type="text" name="remarks" value="{{ old('remarks') }}" class="mt-1 w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                        </div>

                        <div class="flex justify-end gap-2">
                            <button type="button" @click="open = false" class="btn-app btn-md btn-secondary">Cancel</button>
                            <button class="btn-app btn-md" :class="type === 'loan' ? 'btn-brand' : 'btn-danger'" x-text="type === 'loan' ? 'Record loan' : 'Charge missing item'"></button>
                        </div>
                    </form>
                    </div>
                    </template>
                </div>
            @endif
        </div>
    </div>

    <script>
        function deductionForm({ type, total, cutoffs, people = [] }) {
            return {
                type, total, cutoffs, people, search: '',
                matches(haystack) { return !this.search || haystack.includes(this.search.toLowerCase()); },
                perCutoff() { const t = parseFloat(this.total) || 0, n = Math.max(1, parseInt(this.cutoffs) || 1); return Math.ceil(t / n * 100) / 100; },
                perPerson() { const t = parseFloat(this.total) || 0, n = Math.max(1, this.people.length); return Math.floor(t / n * 100) / 100; },
                months() { const n = Math.max(1, parseInt(this.cutoffs) || 1); return n === 1 ? 'one cutoff' : (n / 2).toLocaleString() + ' month(s)'; },
                money(v) { return '₱' + (Number(v) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            };
        }
    </script>
</x-app-layout>
