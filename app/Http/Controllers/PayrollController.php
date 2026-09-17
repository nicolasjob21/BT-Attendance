<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\Setting;
use App\Services\Payroll\PayrollAutomation;
use App\Services\PayrollCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
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
            'autoEnabled' => PayrollAutomation::enabled(),
            'settings' => [
                'pay_date_offset_days' => (int) Setting::get('payroll.pay_date_offset_days', 5),
                'generate_delay_days' => (int) Setting::get('payroll.generate_delay_days', 1),
            ],
            'canAutomate' => $request->user()->hasRole('superadmin'),
        ]);
    }

    /** Run the calculator for every active employee in the period (manual run). */
    public function generate(Request $request, PayrollPeriod $period, PayrollAutomation $automation)
    {
        abort_if($period->isClosed(), 422, 'This period is closed.');

        $n = $automation->generate($period, (string) $request->user()->id, $request->boolean('force'));
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
        abort_if($period->payrollItems()->doesntExist(), 422, 'Generate the payroll before closing the period.');
        $period->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $request->user()->id]);

        return redirect()->route('payroll.index', ['period' => $period->id])->with('status', 'Period '.$period->label().' closed. Payslips are final.');
    }

    /** Super Admin: automation switch and timing. */
    public function updateSettings(Request $request)
    {
        abort_unless($request->user()->hasRole('superadmin'), 403);
        $data = $request->validate([
            'auto_enabled' => ['nullable', 'boolean'],
            'pay_date_offset_days' => ['required', 'integer', 'min:0', 'max:31'],
            'generate_delay_days' => ['required', 'integer', 'min:0', 'max:15'],
        ]);
        Setting::set('payroll.auto_enabled', $request->boolean('auto_enabled'));
        Setting::set('payroll.pay_date_offset_days', (int) $data['pay_date_offset_days']);
        Setting::set('payroll.generate_delay_days', (int) $data['generate_delay_days']);

        return back()->with('status', $request->boolean('auto_enabled')
            ? 'Payroll automation is ON — each cutoff is computed '.$data['generate_delay_days'].' day(s) after it ends.'
            : 'Payroll automation is OFF — run payroll manually from this page.');
    }

    /** Edit one employee's payroll line. */
    public function edit(PayrollItem $item)
    {
        $item->load(['employee', 'payrollPeriod', 'adjuster:id,name']);

        return view('payroll.edit', ['item' => $item, 'period' => $item->payrollPeriod]);
    }

    public function update(Request $request, PayrollItem $item)
    {
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
    public function reset(Request $request, PayrollItem $item, PayrollAutomation $automation)
    {
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

        // Employees may only view their own payslip.
        $employee = $request->user()->employee;
        $isOwner = $employee && $item->employee_id === $employee->id;
        abort_unless($isOwner || $request->user()->canAny(['view all payslips', 'run payroll']), 403);

        return view('payroll.show', compact('item'));
    }
}
