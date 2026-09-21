<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Notifications\PayslipReleased;
use App\Services\Payroll\PayrollRunner;
use App\Services\PayrollCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PayrollController extends Controller
{
    public function index(Request $request, PayrollRunner $runner)
    {
        // Periods are always up to date, even if the scheduler isn't running.
        $runner->rollForward();

        $periods = PayrollPeriod::withCount('payrollItems')
            ->latest('period_start')
            ->get();

        // Default to the cutoff we are in now; fall back to the latest period.
        $today = Carbon::today();
        $selected = $request->integer('period')
            ? $periods->firstWhere('id', $request->integer('period'))
            : ($periods->first(fn ($p) => $p->period_start->lte($today) && $p->period_end->gte($today)) ?? $periods->first());

        $items = $selected
            ? $selected->payrollItems()->with(['employee', 'adjuster:id,name'])->get()->sortBy(fn ($i) => $i->employee?->last_name)->values()
            : collect();

        return view('payroll.index', [
            'periods' => $periods,
            'selected' => $selected,
            'items' => $items,
            'payDay' => $runner->status(),
            'canEdit' => $request->user()->can('manage payroll rates'),
            'byCard' => $items->filter(fn ($i) => $i->employee?->paysByCard())->count(),
        ]);
    }

    /** Super Admin: salaries are out — payslips become visible to every employee. */
    public function release(Request $request, PayrollPeriod $period)
    {
        abort_unless($request->user()->can('manage payroll rates'), 403);
        abort_if($period->payrollItems()->doesntExist(), 422, 'Generate the payroll before releasing it.');
        abort_if($period->isReleased(), 422, 'This payroll was already released.');

        // Releasing also closes the period: the figures that went out are final.
        $period->update([
            'status' => 'closed',
            'closed_at' => $period->closed_at ?? now(),
            'closed_by' => $period->closed_by ?? $request->user()->id,
            'released_at' => now(),
            'released_by' => $request->user()->id,
        ]);

        foreach ($period->payrollItems()->with(['employee.user', 'payrollPeriod'])->get() as $item) {
            $item->employee?->user?->notify(new PayslipReleased($item));
        }

        return redirect()->route('payroll.index', ['period' => $period->id])
            ->with('status', 'Payroll released ('.$period->releaseTiming().'). Every employee can now see and download their payslip.');
    }

    /** The full register as Excel — one row per employee, every column. */
    public function export(PayrollPeriod $period)
    {
        $items = $period->payrollItems()->with('employee')->get()->sortBy(fn ($i) => $i->employee?->last_name);

        $headers = ['Employee No', 'Employee', 'Payout', 'Card / account no.', 'Basic pay', 'Overtime', 'Night diff', 'Holiday', 'Allowances', 'Gross',
            'Late / undertime', 'Absences', 'Half-day', 'SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding tax', 'Other deductions', 'Loan', 'Missing item', 'Total deductions', 'Net pay', 'Adjusted', 'Remarks'];
        $rows = $items->map(fn (PayrollItem $i) => [
            $i->employee?->employee_no, $i->employee?->full_name, $i->employee?->paysByCard() ? 'Card' : 'Cash', $i->employee?->bank_account_no,
            (float) $i->basic_pay, (float) $i->overtime_pay, (float) $i->night_diff_pay, (float) $i->holiday_pay, (float) $i->allowances, (float) $i->gross_pay,
            (float) $i->late_undertime_deduction, (float) $i->absences_deduction, (float) $i->half_day_deduction, (float) $i->sss_deduction, (float) $i->philhealth_deduction,
            (float) $i->pagibig_deduction, (float) $i->withholding_tax, (float) $i->other_deductions, (float) $i->loan_deduction, (float) $i->missing_item_deduction, (float) $i->total_deductions, (float) $i->net_pay,
            $i->isAdjusted() ? 'Yes' : '', $i->remarks,
        ])->all();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Payroll');
        $sheet->setCellValue('A1', 'Brite TSI — Payroll register · '.$period->label().' · pay date '.($period->pay_date?->format('M j, Y') ?? '—')
            .($period->isReleased() ? ' · released '.$period->released_at->format('M j, Y') : ''));
        $sheet->mergeCells('A1:X1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->fromArray([$headers, ...$rows], null, 'A3');
        $last = 3 + count($rows);
        $sheet->getStyle('A3:X3')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A3:X3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E7490');
        $sheet->getStyle("E4:V{$last}")->getNumberFormat()->setFormatCode('#,##0.00');
        // Totals row
        $t = $last + 1;
        $sheet->setCellValue("B{$t}", 'TOTAL');
        foreach (range('E', 'V') as $col) {
            $sheet->setCellValue("{$col}{$t}", "=SUM({$col}4:{$col}{$last})");
        }
        $sheet->getStyle("A{$t}:X{$t}")->getFont()->setBold(true);
        $sheet->getStyle("E{$t}:V{$t}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', 'X') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('C4');

        $writer = new Xlsx($spreadsheet);
        $name = 'payroll-'.$period->period_start->format('Y-m-d').'-to-'.$period->period_end->format('Y-m-d').'.xlsx';

        return response()->streamDownload(fn () => $writer->save('php://output'), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Every payslip of the period on one printable page (one per sheet) — for envelopes. */
    /**
     * Print view: one employee (?employee=ID, picked from the search box),
     * a payout batch (?method=cash|card, e.g. for envelopes), or everyone.
     */
    public function printBatch(Request $request, PayrollPeriod $period)
    {
        $method = in_array($request->string('method')->toString(), ['card', 'cash'], true) ? $request->string('method')->toString() : null;
        $employeeId = $request->integer('employee') ?: null;

        $items = $period->payrollItems()->with(['employee', 'payrollPeriod'])
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->get()
            ->when($method, fn ($c) => $c->filter(fn ($i) => $i->employee?->payout_method === $method))
            ->sortBy(fn ($i) => $i->employee?->last_name)->values();

        abort_if($employeeId && $items->isEmpty(), 404, 'No payslip for that employee in this period.');

        return view('payroll.print', ['period' => $period, 'items' => $items, 'method' => $method, 'single' => $employeeId ? $items->first() : null]);
    }

    /** Employee: my released payslips. */
    public function mine(Request $request)
    {
        $employee = $request->user()->employee;
        $items = $employee
            ? $employee->payrollItems()->with('payrollPeriod')->get()
                ->filter(fn ($i) => $i->payrollPeriod?->isReleased())
                ->sortByDesc(fn ($i) => $i->payrollPeriod->period_start)->values()
            : collect();

        $ytd = $items->filter(fn ($i) => $i->payrollPeriod->period_start->year === now()->year);
        $summary = [
            'last' => $items->first(),
            'ytd_net' => (float) $ytd->sum('net_pay'),
            'ytd_count' => $ytd->count(),
            'method' => $employee?->paysByCard() ? 'Card' : 'Cash',
            'next' => PayrollPeriod::cutoffFor(now())['end'],
        ];

        return view('payroll.mine', compact('items', 'summary'));
    }

    /** Run the calculator for every active employee in the period (manual run). */
    public function generate(Request $request, PayrollPeriod $period, PayrollRunner $runner)
    {
        abort_if($period->isClosed(), 422, 'This period is closed.');

        $n = $runner->generate($period, (string) $request->user()->id, $request->boolean('force'));
        $kept = $period->payrollItems()->whereNotNull('adjusted_at')->count();

        return redirect()
            ->route('payroll.index', ['period' => $period->id])
            ->with('status', "Payroll computed for {$n} employees.".($kept && ! $request->boolean('force') ? " {$kept} manually adjusted line(s) were kept as-is." : ''));
    }

    /** Create the next semi-monthly period (after the latest one, or the current cutoff). */
    public function createPeriod()
    {
        $latest = PayrollPeriod::latest('period_end')->first();
        $period = $latest ? $latest->next() : PayrollPeriod::ensureFor(Carbon::now());

        return redirect()->route('payroll.index', ['period' => $period->id])->with('status', 'Period '.$period->label().' is ready.');
    }

    /** Lock the period: no more recalculation or edits. */
    public function close(Request $request, PayrollPeriod $period)
    {
        abort_unless($request->user()->can('manage payroll rates'), 403);
        abort_if($period->payrollItems()->doesntExist(), 422, 'Generate the payroll before closing the period.');
        $period->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $request->user()->id]);

        return redirect()->route('payroll.index', ['period' => $period->id])->with('status', 'Period '.$period->label().' closed. Payslips are final.');
    }

    /** Edit one employee's payroll line. */
    public function edit(Request $request, PayrollItem $item)
    {
        abort_unless($request->user()->can('manage payroll rates'), 403);
        $item->load(['employee', 'payrollPeriod', 'adjuster:id,name']);

        return view('payroll.edit', ['item' => $item, 'period' => $item->payrollPeriod]);
    }

    public function update(Request $request, PayrollItem $item)
    {
        abort_unless($request->user()->can('manage payroll rates'), 403);
        $period = $item->payrollPeriod;
        abort_if($period->isClosed(), 422, 'This period is closed.');

        $rules = array_fill_keys(PayrollItem::EDITABLE, ['required', 'numeric', 'min:0', 'max:9999999']);
        $rules['remarks'] = ['nullable', 'string', 'max:500'];
        $data = $request->validate($rules);

        $item->fill($data);
        $item->adjusted_at = now();
        $item->adjusted_by = $request->user()->id;
        $item->recomputeTotals()->save();

        return redirect()->route('payroll.index', ['period' => $period->id])
            ->with('status', 'Payroll line for '.$item->employee->full_name.' updated — net ₱'.number_format((float) $item->net_pay, 2).'.');
    }

    /** Throw away manual edits on one line and recompute it. */
    public function reset(Request $request, PayrollItem $item)
    {
        abort_unless($request->user()->can('manage payroll rates'), 403);
        $period = $item->payrollPeriod;
        abort_if($period->isClosed(), 422, 'This period is closed.');

        app(PayrollCalculator::class)->calculate($item->employee, $period, force: true);

        return redirect()->route('payroll.index', ['period' => $period->id])->with('status', 'Line recomputed from attendance and rates.');
    }

    /**
     * Full salary/payroll history for one employee across every period,
     * newest first. For HR to review how an employee has been paid over time.
     */
    public function salaryHistory(Employee $employee)
    {
        $items = $employee->payrollItems()
            ->with('payrollPeriod')
            ->get()
            ->sortByDesc(fn (PayrollItem $item) => $item->payrollPeriod?->period_start)
            ->values();

        return view('payroll.salary-history', compact('employee', 'items'));
    }

    /** On-screen payslip for a single payroll line. */
    public function show(Request $request, PayrollItem $item)
    {
        $item->load(['employee', 'payrollPeriod']);

        // Employees may only view their own payslip, and only once the payroll is released.
        $employee = $request->user()->employee;
        $isOwner = $employee && $item->employee_id === $employee->id;
        $isPayrollStaff = $request->user()->canAny(['view all payslips', 'run payroll']);
        abort_unless($isOwner || $isPayrollStaff, 403);
        abort_unless($isPayrollStaff || $item->payrollPeriod->isReleased(), 404, 'This payslip is not released yet.');

        return view('payroll.show', compact('item'));
    }
}
