<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculator;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $periods = PayrollPeriod::withCount('payrollItems')
            ->latest('period_start')
            ->get();

        $selected = $request->integer('period')
            ? $periods->firstWhere('id', $request->integer('period'))
            : $periods->first();

        $items = $selected
            ? $selected->payrollItems()->with('employee')->get()
            : collect();

        return view('payroll.index', compact('periods', 'selected', 'items'));
    }

    /** Run the PayrollCalculator for every active employee in the period. */
    public function generate(Request $request, PayrollPeriod $period, PayrollCalculator $calculator)
    {
        $employees = Employee::where('status', 'active')->get();
        foreach ($employees as $employee) {
            $calculator->calculate($employee, $period);
        }

        $period->update(['status' => 'processing']);

        return redirect()
            ->route('payroll.index', ['period' => $period->id])
            ->with('status', "Payroll computed for {$employees->count()} employees.");
    }

    /** On-screen payslip for a single payroll line. */
    public function show(Request $request, PayrollItem $item)
    {
        $item->load(['employee', 'payrollPeriod']);

        // Employees may only view their own payslip.
        $employee = $request->user()->employee;
        $isOwner = $employee && $item->employee_id === $employee->id;
        abort_unless($isOwner || $request->user()->can('run payroll'), 403);

        return view('payroll.show', compact('item'));
    }
}
