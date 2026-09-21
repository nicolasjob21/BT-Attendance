<?php

namespace Tests\Feature;

use App\Models\ContributionRate;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Notifications\PayrollDue;
use App\Notifications\PayslipReleased;
use App\Services\Payroll\PayrollRunner;
use App\Services\Payroll\PayrollRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PayrollRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        PayrollPeriod::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::where('username', 'brite-admin')->firstOrFail();
    }

    public function test_tick_keeps_current_and_next_periods_ready(): void
    {
        Carbon::setTestNow('2026-09-20 01:00');
        $r = app(PayrollRunner::class)->tick();

        $this->assertSame(2, $r['periods_created']);
        $this->assertSame([], $r['reminded']);
        $sep = PayrollPeriod::whereDate('period_start', '2026-09-16')->firstOrFail();
        $this->assertSame('2026-09-30', $sep->period_end->toDateString());
        $this->assertSame('second_half', $sep->cutoff_type);
        $this->assertSame('2026-09-30', $sep->pay_date->toDateString());
        $oct = PayrollPeriod::whereDate('period_start', '2026-10-01')->firstOrFail();
        $this->assertSame('2026-10-15', $oct->period_end->toDateString());
        $this->assertSame('first_half', $oct->cutoff_type);

        // Idempotent.
        $this->assertSame(0, app(PayrollRunner::class)->tick()['periods_created']);
    }

    public function test_pay_day_reminder_goes_out_once_on_the_morning_of_pay_day(): void
    {
        Notification::fake();

        Carbon::setTestNow('2026-09-29 07:00');
        $this->assertSame([], app(PayrollRunner::class)->tick()['reminded']); // creates Sep 16–30 + Oct 1–15; not pay day yet
        Notification::assertNothingSent();

        Carbon::setTestNow('2026-09-30 07:00'); // pay day = last day of the cutoff, morning tick
        $r = app(PayrollRunner::class)->tick();
        $period = PayrollPeriod::whereDate('period_end', '2026-09-30')->firstOrFail();

        $this->assertSame(['Sep 16 – Sep 30, 2026'], $r['reminded']);
        $this->assertNotNull($period->reminded_at);
        $this->assertNull($period->generated_at); // nothing is computed on its own
        Notification::assertSentTo($this->admin(), PayrollDue::class);
        Notification::assertSentTo(User::where('username', 'brite-hr')->firstOrFail(), PayrollDue::class);

        // Not reminded twice.
        Notification::fake();
        $this->assertSame([], app(PayrollRunner::class)->tick()['reminded']);
        Notification::assertNothingSent();
    }

    public function test_status_tells_the_admin_what_payroll_needs_right_now(): void
    {
        $runner = app(PayrollRunner::class);
        $admin = $this->admin();

        Carbon::setTestNow('2026-09-20 09:00');
        $s = $runner->status();
        $this->assertSame('upcoming', $s['state']);
        $this->assertSame('2026-09-30', $s['pay_date']->toDateString());
        $this->assertSame(10, $s['days']);
        $this->actingAs($admin)->get('/payroll')->assertOk()->assertSee('Next pay day: Wed, Sep 30')->assertDontSee('Automate');
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('Next pay day Sep 30');

        Carbon::setTestNow('2026-09-30 09:00'); // pay day
        $s = $runner->status();
        $this->assertSame('due', $s['state']);
        $this->actingAs($admin)->get('/payroll')->assertOk()->assertSee('Pay day is today')->assertSee('Run payroll');
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('Pay day is today');

        $period = PayrollPeriod::whereDate('period_end', '2026-09-30')->firstOrFail();
        $this->actingAs($admin)->post("/payroll/{$period->id}/generate")->assertRedirect();
        $s = $runner->status();
        $this->assertSame('computed', $s['state']);
        $this->actingAs($admin)->get('/payroll')->assertOk()->assertSee('Ready to release');

        $this->actingAs($admin)->post("/payroll/{$period->id}/release")->assertRedirect();
        $s = $runner->status();
        $this->assertSame('upcoming', $s['state']);
        $this->assertSame('2026-10-15', $s['pay_date']->toDateString());

        // A cutoff nobody ran shows as overdue until it is computed.
        Carbon::setTestNow('2026-10-17 09:00');
        $s = $runner->status();
        $this->assertSame('overdue', $s['state']);
        $this->assertSame('2026-10-15', $s['pay_date']->toDateString());
        $this->actingAs($admin)->get('/payroll')->assertOk()->assertSee('Payroll overdue')->assertSee('2 days ago');
    }

    public function test_superadmin_edits_company_wide_rates_and_they_apply_to_everyone(): void
    {
        Carbon::setTestNow('2026-10-01 09:00');
        $admin = $this->admin();
        $this->actingAs($admin)->get('/payroll/rates')->assertOk()->assertSee('Earnings')->assertSee('Deductions')->assertSee('SSS');

        $sss = ContributionRate::where('contribution_type', 'sss')->firstOrFail();
        $brackets = ContributionRate::all()->mapWithKeys(fn ($b) => [$b->id => [
            'min_salary' => (float) $b->min_salary, 'max_salary' => $b->max_salary,
            'employee_rate' => round($b->employee_rate * 100, 2), 'employer_rate' => round($b->employer_rate * 100, 2),
        ]])->all();
        $brackets[$sss->id]['employee_rate'] = 4.5; // was 5 %

        $this->actingAs($admin)->put('/payroll/rates', [
            'rates' => ['basic_cutoff_percent' => 50, 'working_days_per_month' => 22, 'hours_per_day' => 8, 'allowance_percent' => 10,
                'ot_regular_percent' => 125, 'ot_rest_day_percent' => 130, 'ot_holiday_percent' => 200, 'withholding_tax_percent' => 2, 'pagibig_monthly_cap' => 200],
            'brackets' => $brackets,
        ])->assertRedirect(route('payroll.rates'));

        $this->assertEquals(0.045, (float) $sss->fresh()->employee_rate);
        $this->assertEquals(10.0, PayrollRates::get('allowance_percent'));

        // Run payroll: everyone gets a 10 % allowance and 2 % tax, and the new SSS rate.
        $this->actingAs($admin)->post('/payroll/periods');
        $period = PayrollPeriod::firstOrFail();
        $this->actingAs($admin)->post("/payroll/{$period->id}/generate");
        $hr = Employee::where('email', 'hr@brite-tsi.com')->firstOrFail(); // 35,000 monthly
        $line = $period->payrollItems()->where('employee_id', $hr->id)->firstOrFail();

        $this->assertEquals(17500.00, (float) $line->basic_pay);
        $this->assertEquals(1750.00, (float) $line->allowances);
        $this->assertEquals(round(35000 * 0.045 / 2, 2), (float) $line->sss_deduction);
        $taxable = (float) $line->gross_pay - (float) $line->sss_deduction - (float) $line->philhealth_deduction - (float) $line->pagibig_deduction;
        $this->assertEquals(round($taxable * 0.02, 2), (float) $line->withholding_tax);

        $this->actingAs($admin)->get('/payroll?period='.$period->id)->assertOk()->assertSee('of gross')->assertSee('kept as net');

        // Only the Super Admin can open the rates page — not Admin (HR), Developer or Employee.
        foreach (['brite-hr', 'brite-dev', 'brite-tech'] as $u) {
            $this->actingAs(User::where('username', $u)->firstOrFail())->get('/payroll/rates')->assertForbidden();
        }
    }

    public function test_release_makes_payslips_visible_to_employees_and_exports_the_register(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-01 09:00');
        $admin = $this->admin();
        $hr = User::where('username', 'brite-hr')->firstOrFail();
        $techUser = User::where('username', 'brite-tech')->firstOrFail();

        $this->actingAs($admin)->post('/payroll/periods');
        $period = PayrollPeriod::firstOrFail();
        $this->actingAs($admin)->post("/payroll/{$period->id}/generate");
        $line = $period->payrollItems()->where('employee_id', $techUser->employee->id)->firstOrFail();

        // Before release: the employee cannot see the payslip, My Payslips is empty.
        $this->actingAs($techUser)->get("/payroll/item/{$line->id}")->assertNotFound();
        $this->actingAs($techUser)->get('/my-payslips')->assertOk()->assertSee('No released payslips yet');

        // Only the Super Admin may edit lines or release; Admin (HR) may not.
        $this->actingAs($hr)->get("/payroll/lines/{$line->id}/edit")->assertForbidden();
        $this->actingAs($hr)->post("/payroll/{$period->id}/release")->assertForbidden();

        // Admin can download the register and print payslips.
        $export = $this->actingAs($hr)->get("/payroll/{$period->id}/export")->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $export->headers->get('content-type'));
        $this->assertStringContainsString('payroll-2026-10-01-to-2026-10-15.xlsx', $export->headers->get('content-disposition'));
        $this->actingAs($hr)->get("/payroll/{$period->id}/print?method=cash")->assertOk()->assertSee('Cash employees');
        // Search box: print one employee's payslip only.
        $this->actingAs($hr)->get('/payroll?period='.$period->id)->assertOk()->assertSee('Print payslip: search employee');
        $single = $this->actingAs($hr)->get("/payroll/{$period->id}/print?employee={$techUser->employee->id}")->assertOk();
        $single->assertSee('Payslip · <b>'.$techUser->employee->full_name.'</b>', false)->assertDontSee('System Admin');
        $this->actingAs($hr)->get("/payroll/{$period->id}/print?employee=999999")->assertNotFound();

        // Release.
        $this->actingAs($admin)->post("/payroll/{$period->id}/release")->assertRedirect()->assertSessionHas('status');
        $period->refresh();
        $this->assertTrue($period->isReleased());
        $this->assertSame('closed', $period->status);
        $this->assertSame('14 day(s) early', $period->releaseTiming()); // pay date Oct 15, released Oct 1
        Notification::assertSentTo($techUser, PayslipReleased::class);

        // After release: the employee sees and can open the payslip; nobody can edit any more.
        $this->actingAs($techUser)->get('/my-payslips')->assertOk()->assertSee('Oct 1 – Oct 15, 2026')->assertSee('14 day(s) early');
        $this->actingAs($techUser)->get("/payroll/item/{$line->id}")->assertOk()->assertSee('Released Oct 1, 2026');
        $this->actingAs($admin)->put("/payroll/lines/{$line->id}", collect(PayrollItem::EDITABLE)->mapWithKeys(fn ($k) => [$k => 0])->all())->assertStatus(422);
        $this->actingAs($admin)->post("/payroll/{$period->id}/release")->assertStatus(422);

        // Another employee can never see someone else's payslip.
        $other = User::where('username', 'brite-dev')->firstOrFail();
        $this->actingAs($other)->get("/payroll/item/{$line->id}")->assertForbidden();
    }

    public function test_late_and_undertime_are_deducted_and_early_arrival_does_not_offset(): void
    {
        Carbon::setTestNow('2026-10-16 09:00');
        $admin = $this->admin();
        $hr = Employee::where('email', 'hr@brite-tsi.com')->firstOrFail(); // 35,000/month → 1,590.91/day → 198.86/h → 3.3144/min
        $day = fn ($t) => Carbon::parse("2026-10-05 $t");

        // Mon Oct 5: in 7:30 (early), out 16:30 (1 h early) → 60 min undertime, early arrival ignored.
        $hr->attendanceLogs()->create(['log_type' => 'time_in', 'logged_at' => $day('07:30')]);
        $hr->attendanceLogs()->create(['log_type' => 'time_out', 'logged_at' => $day('16:30')]);
        // Tue Oct 6: in 9:00 (30 min late, 15 min grace → 15 min), out 17:30 on time.
        $hr->attendanceLogs()->create(['log_type' => 'time_in', 'logged_at' => Carbon::parse('2026-10-06 09:00')]);
        $hr->attendanceLogs()->create(['log_type' => 'time_out', 'logged_at' => Carbon::parse('2026-10-06 17:30')]);
        // Wed Oct 7: out 15:00 but with an APPROVED early-leave → excused.
        $hr->attendanceLogs()->create(['log_type' => 'time_in', 'logged_at' => Carbon::parse('2026-10-07 08:30')]);
        $hr->attendanceLogs()->create(['log_type' => 'time_out', 'logged_at' => Carbon::parse('2026-10-07 15:00')]);
        $hr->leaveRequests()->create(['date_from' => '2026-10-07', 'date_to' => '2026-10-07', 'days' => 0, 'day_portion' => 'full', 'is_early_leave' => true, 'requested_time_out' => '15:00', 'status' => 'approved', 'reason' => 'Doctor']);

        $period = PayrollPeriod::ensureFor(Carbon::parse('2026-10-05'));
        $this->actingAs($admin)->post("/payroll/{$period->id}/generate");
        $line = $period->payrollItems()->where('employee_id', $hr->id)->firstOrFail();

        $perMinute = (35000 / 22 / 8) / 60;
        $this->assertEquals(round(75 * $perMinute, 2), (float) $line->late_undertime_deduction);
    }

    /** Edit / release follow the "manage payroll rates" permission, not the Super Admin role by name. */
    public function test_edit_button_follows_the_payroll_rates_permission_not_the_role(): void
    {
        Carbon::setTestNow('2026-10-01 09:00');
        $admin = $this->admin();
        $this->actingAs($admin)->post('/payroll/periods');
        $period = PayrollPeriod::firstOrFail();
        $this->actingAs($admin)->post("/payroll/{$period->id}/generate");
        $line = $period->payrollItems()->firstOrFail();

        // Admin (HR) can run payroll but not edit lines: no Edit button, edit page forbidden.
        $hr = User::where('username', 'brite-hr')->firstOrFail();
        $this->assertFalse($hr->can('manage payroll rates'));
        $this->actingAs($hr)->get('/payroll?period='.$period->id)->assertOk()->assertDontSee(route('payroll.lines.edit', $line));
        $this->actingAs($hr)->get("/payroll/lines/{$line->id}/edit")->assertForbidden();

        // Grant the permission to a non-Super Admin (as the Developer holds it "for now"): Edit appears and works.
        $hr->givePermissionTo('manage payroll rates');
        $this->actingAs($hr->fresh())->get('/payroll?period='.$period->id)->assertOk()->assertSee(route('payroll.lines.edit', $line))->assertSee('Pay day');
        $this->actingAs($hr->fresh())->get("/payroll/lines/{$line->id}/edit")->assertOk();
    }

    public function test_admin_can_edit_a_line_and_manual_edits_survive_recalculation(): void
    {
        Carbon::setTestNow('2026-10-01 09:00');
        $admin = $this->admin();
        $this->actingAs($admin)->post('/payroll/periods')->assertRedirect();
        $period = PayrollPeriod::firstOrFail();
        $this->actingAs($admin)->post("/payroll/{$period->id}/generate")->assertRedirect();

        $item = $period->payrollItems()->firstOrFail();
        $this->actingAs($admin)->get("/payroll/lines/{$item->id}/edit")->assertOk()->assertSee('Earnings');

        $payload = collect(PayrollItem::EDITABLE)->mapWithKeys(fn ($k) => [$k => (float) $item->$k])->all();
        $payload['allowances'] = 1500;
        $payload['other_deductions'] = 200;
        $payload['remarks'] = 'Site allowance';
        $this->actingAs($admin)->put("/payroll/lines/{$item->id}", $payload)->assertRedirect(route('payroll.index', ['period' => $period->id]));

        $item->refresh();
        $this->assertTrue($item->isAdjusted());
        $this->assertSame($admin->id, $item->adjusted_by);
        $this->assertEquals(round((float) $item->basic_pay + (float) $item->overtime_pay + 1500, 2), (float) $item->gross_pay);
        $this->assertEquals(round((float) $item->gross_pay - (float) $item->total_deductions, 2), (float) $item->net_pay);
        $this->assertEquals(200, (float) $item->other_deductions);

        // Recalculate keeps the adjusted line; reset recomputes it (allowances survive as HR input).
        $this->actingAs($admin)->post("/payroll/{$period->id}/generate")->assertSessionHas('status');
        $this->assertTrue($item->fresh()->isAdjusted());
        $this->actingAs($admin)->post("/payroll/lines/{$item->id}/reset")->assertRedirect();
        $this->assertFalse($item->fresh()->isAdjusted());
        $this->assertEquals(1500, (float) $item->fresh()->allowances);

        // Closing locks everything.
        $this->actingAs($admin)->post("/payroll/{$period->id}/close")->assertRedirect();
        $this->assertSame('closed', $period->fresh()->status);
        $this->actingAs($admin)->put("/payroll/lines/{$item->id}", $payload)->assertStatus(422);
        $this->actingAs($admin)->post("/payroll/{$period->id}/generate")->assertStatus(422);
    }
}
