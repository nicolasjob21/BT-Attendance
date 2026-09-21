<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayrollDeduction;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\Site;
use App\Services\PayrollCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Loans and missing-item charges: balances that payroll pays down one
 * installment per cutoff. Anyone who runs payroll can see them; only the
 * Super Admin (manage payroll rates) records, changes or cancels one.
 */
class PayrollDeductionController extends Controller
{
    public function __construct(private PayrollCalculator $calculator) {}

    public function index(Request $request)
    {
        $type = in_array($request->string('type')->toString(), array_keys(PayrollDeduction::TYPES), true) ? $request->string('type')->toString() : null;
        $status = in_array($request->string('status')->toString(), ['active', 'paid', 'cancelled'], true) ? $request->string('status')->toString() : 'active';

        $deductions = PayrollDeduction::with(['employee:id,first_name,last_name,employee_no', 'site:id,name', 'creator:id,name'])
            ->withCount('payments')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->where('status', $status)
            ->orderByDesc('id')
            ->paginate(25)->withQueryString();

        $active = PayrollDeduction::where('status', PayrollDeduction::ACTIVE);
        $stats = [
            'loans' => (clone $active)->where('type', PayrollDeduction::LOAN)->count(),
            'loan_balance' => (float) (clone $active)->where('type', PayrollDeduction::LOAN)->sum('balance'),
            'missing' => (clone $active)->where('type', PayrollDeduction::MISSING_ITEM)->count(),
            'missing_balance' => (float) (clone $active)->where('type', PayrollDeduction::MISSING_ITEM)->sum('balance'),
            'next_cutoff' => (float) (clone $active)->get()->sum(fn ($d) => $d->nextInstallment()),
        ];

        return view('payroll.deductions.index', [
            'deductions' => $deductions,
            'type' => $type,
            'status' => $status,
            'stats' => $stats,
            'employees' => Employee::where('status', 'active')->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'employee_no']),
            'sites' => Site::orderByRaw("type = 'office' desc")->orderBy('name')->get(['id', 'name', 'type']),
            'nextCutoff' => PayrollPeriod::cutoffFor(Carbon::now())['end'],
            'canManage' => $request->user()->can('manage payroll rates'),
        ]);
    }

    /** Record a loan (one employee) or a missing item (split across the employees responsible). */
    public function store(Request $request)
    {
        abort_unless($request->user()->can('manage payroll rates'), 403);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(PayrollDeduction::TYPES))],
            'description' => ['required', 'string', 'max:255'],
            'total_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'cutoffs' => ['required', 'integer', 'min:1', 'max:120'],
            'starts_on' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            // loan
            'employee_id' => ['required_if:type,loan', 'nullable', Rule::exists('employees', 'id')],
            // missing item
            'site_id' => ['required_if:type,missing_item', 'nullable', Rule::exists('sites', 'id')],
            'incident_date' => ['nullable', 'date'],
            'employees' => ['required_if:type,missing_item', 'array'],
            'employees.*' => [Rule::exists('employees', 'id')],
        ], [
            'employees.required_if' => 'Pick at least one employee responsible for the missing item.',
            'employee_id.required_if' => 'Pick the employee who took the loan.',
            'site_id.required_if' => 'Pick the site where the item went missing.',
        ]);

        $total = round((float) $data['total_amount'], 2);
        $cutoffs = (int) $data['cutoffs'];
        $startsOn = Carbon::parse($data['starts_on'])->toDateString();

        $created = DB::transaction(function () use ($data, $total, $cutoffs, $startsOn, $request) {
            $rows = [];
            if ($data['type'] === PayrollDeduction::LOAN) {
                $rows[] = [$data['employee_id'], $total];
            } else {
                // Split the cost evenly; any rounding remainder goes on the first person.
                $ids = array_values(array_unique(array_map('intval', $data['employees'])));
                $share = floor($total / count($ids) * 100) / 100;
                $remainder = round($total - $share * count($ids), 2);
                foreach ($ids as $i => $id) {
                    $rows[] = [$id, round($share + ($i === 0 ? $remainder : 0), 2)];
                }
            }

            $group = $data['type'] === PayrollDeduction::MISSING_ITEM ? (string) Str::uuid() : null;
            $created = collect();
            foreach ($rows as [$employeeId, $amount]) {
                $created->push(PayrollDeduction::create([
                    'employee_id' => $employeeId,
                    'type' => $data['type'],
                    'site_id' => $data['type'] === PayrollDeduction::MISSING_ITEM ? $data['site_id'] : null,
                    'group_id' => $group,
                    'description' => $data['description'],
                    'incident_date' => $data['incident_date'] ?? null,
                    'total_amount' => $amount,
                    'installment_amount' => round(ceil($amount / $cutoffs * 100) / 100, 2),
                    'balance' => $amount,
                    'starts_on' => $startsOn,
                    'status' => PayrollDeduction::ACTIVE,
                    'remarks' => $data['remarks'] ?? null,
                    'created_by' => $request->user()->id,
                ]));
            }

            return $created;
        });

        // If the cutoff it starts in has already been computed (and not
        // released), refresh those lines so the deduction shows up right away.
        $created->each(fn (PayrollDeduction $d) => $this->refreshComputedLine($d));

        $label = $data['type'] === PayrollDeduction::LOAN
            ? 'Loan recorded: ₱'.number_format($total, 2).' over '.$cutoffs.' cutoff(s).'
            : 'Missing item charged to '.$created->count().' employee(s): ₱'.number_format($total, 2).' over '.$cutoffs.' cutoff(s).';

        return redirect()->route('payroll.deductions', ['type' => $data['type']])->with('status', $label);
    }

    /** Change the installment, start, or remarks of one balance. */
    public function update(Request $request, PayrollDeduction $deduction)
    {
        abort_unless($request->user()->can('manage payroll rates'), 403);
        abort_unless($deduction->isActive(), 422, 'Only an active balance can be changed.');

        $data = $request->validate([
            'installment_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'starts_on' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $deduction->update([
            'installment_amount' => round((float) $data['installment_amount'], 2),
            'starts_on' => Carbon::parse($data['starts_on'])->toDateString(),
            'remarks' => $data['remarks'] ?? null,
        ]);
        $this->refreshComputedLine($deduction);

        return back()->with('status', 'Updated. Next cutoff takes ₱'.number_format($deduction->nextInstallment(), 2).'.');
    }

    /** Stop collecting; what was already taken stays on the payslips it appeared on. */
    public function cancel(Request $request, PayrollDeduction $deduction)
    {
        abort_unless($request->user()->can('manage payroll rates'), 403);
        abort_unless($deduction->isActive(), 422, 'This balance is not active.');

        $deduction->update([
            'status' => PayrollDeduction::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->id,
            'remarks' => trim(($deduction->remarks ? $deduction->remarks."\n" : '').'Cancelled: '.$request->string('reason')->toString()) ?: null,
        ]);
        $this->refreshComputedLine($deduction);

        return back()->with('status', 'Cancelled — ₱'.number_format((float) $deduction->balance, 2).' will not be collected.');
    }

    /**
     * The cutoff a deduction first lands in may already have a computed (but
     * unreleased) line. Recompute it so the register matches — unless the
     * Super Admin edited that line by hand, which is never overwritten.
     */
    private function refreshComputedLine(PayrollDeduction $deduction): void
    {
        $periods = PayrollPeriod::whereNull('released_at')
            ->whereDate('period_end', '>=', $deduction->starts_on->toDateString())
            ->whereNotNull('generated_at')
            ->orderBy('period_start')
            ->get();

        foreach ($periods as $period) {
            $item = PayrollItem::where('employee_id', $deduction->employee_id)->where('payroll_period_id', $period->id)->first();
            if ($item && ! $item->isAdjusted()) {
                $this->calculator->calculate($deduction->employee, $period);
            }
        }
    }
}
