<?php

namespace App\Http\Controllers;

use App\Models\ContributionRate;
use App\Models\Employee;
use App\Services\Payroll\PayrollRates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payroll Rates (`manage payroll rates`): the company-wide rules behind every
 * employee's payroll, shown and edited as percentages — earnings on one side,
 * deductions on the other — with a live preview on a sample salary.
 */
class PayrollRatesController extends Controller
{
    public function index()
    {
        $active = Employee::where('status', 'active');
        $sample = (float) ($active->avg('monthly_salary') ?: 20000);

        return view('payroll.rates', [
            'rates' => PayrollRates::all(),
            'labels' => PayrollRates::LABELS,
            'brackets' => ContributionRate::orderBy('contribution_type')->orderBy('min_salary')->get(),
            'sample' => round($sample, 2),
            'employees' => (clone $active)->count(),
            'payrollMonthly' => (float) (clone $active)->sum('monthly_salary'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'rates' => ['required', 'array'],
            'rates.basic_cutoff_percent' => ['required', 'numeric', 'min:1', 'max:100'],
            'rates.working_days_per_month' => ['required', 'integer', 'min:1', 'max:31'],
            'rates.hours_per_day' => ['required', 'numeric', 'min:1', 'max:24'],
            'rates.allowance_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'rates.ot_regular_percent' => ['required', 'numeric', 'min:100', 'max:400'],
            'rates.ot_rest_day_percent' => ['required', 'numeric', 'min:100', 'max:400'],
            'rates.ot_holiday_percent' => ['required', 'numeric', 'min:100', 'max:400'],
            'rates.withholding_tax_percent' => ['required', 'numeric', 'min:0', 'max:50'],
            'rates.pagibig_monthly_cap' => ['required', 'numeric', 'min:0', 'max:100000'],
            'brackets' => ['nullable', 'array'],
            'brackets.*.employee_rate' => ['required', 'numeric', 'min:0', 'max:50'],   // percent
            'brackets.*.employer_rate' => ['required', 'numeric', 'min:0', 'max:50'],   // percent
            'brackets.*.min_salary' => ['required', 'numeric', 'min:0'],
            'brackets.*.max_salary' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($data) {
            PayrollRates::set($data['rates']);
            foreach ($data['brackets'] ?? [] as $id => $b) {
                ContributionRate::whereKey($id)->update([
                    'employee_rate' => round($b['employee_rate'] / 100, 4),
                    'employer_rate' => round($b['employer_rate'] / 100, 4),
                    'min_salary' => $b['min_salary'],
                    'max_salary' => $b['max_salary'] === null || $b['max_salary'] === '' ? null : $b['max_salary'],
                ]);
            }
        });

        return redirect()->route('payroll.rates')->with('status', 'Payroll rates saved. They apply the next time a payroll is computed or recalculated (manually adjusted lines are kept).');
    }
}
