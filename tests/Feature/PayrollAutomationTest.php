<?php

namespace Tests\Feature;

use App\Models\ContributionRate;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Notifications\PayrollGenerated;
use App\Services\Payroll\PayrollAutomation;
use App\Services\Payroll\PayrollRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PayrollAutomationTest extends TestCase
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

    public function test_tick_keeps_current_and_next_periods_ready_without_generating_when_off(): void
    {
        Carbon::setTestNow('2026-09-20 01:00');
        $r = app(PayrollAutomation::class)->tick();

        $this->assertSame(2, $r['periods_created']);
        $this->assertSame([], $r['generated']);
        $sep = PayrollPeriod::whereDate('period_start', '2026-09-16')->firstOrFail();
        $this->assertSame('2026-09-30', $sep->period_end->toDateString());
        $this->assertSame('second_half', $sep->cutoff_type);
        $this->assertSame('2026-10-05', $sep->pay_date->toDateString());
        $oct = PayrollPeriod::whereDate('period_start', '2026-10-01')->firstOrFail();
        $this->assertSame('2026-10-15', $oct->period_end->toDateString());
        $this->assertSame('first_half', $oct->cutoff_type);

        // Idempotent.
        $this->assertSame(0, app(PayrollAutomation::class)->tick()['periods_created']);
    }

    public function test_superadmin_can_switch_automation_on_and_it_generates_after_the_cutoff(): void
    {
        Notification::fake();
        $this->actingAs($this->admin())->put('/payroll/settings', ['auto_enabled' => 1, 'pay_date_offset_days' => 5, 'generate_delay_days' => 1])
            ->assertSessionHas('status');
        $this->assertTrue(PayrollAutomation::enabled());

        Carbon::setTestNow('2026-09-30 01:00');
        app(PayrollAutomation::class)->tick(); // creates Sep 16–30 + Oct 1–15; nothing due yet
        $this->assertSame(0, PayrollItem::count());

        Carbon::setTestNow('2026-10-01 01:00'); // 1 day after Sep 30 cutoff ends
        $r = app(PayrollAutomation::class)->tick();
        $period = PayrollPeriod::whereDate('period_end', '2026-09-30')->firstOrFail();

        $this->assertSame(['Sep 16 – Sep 30, 2026'], $r['generated']);
        $this->assertSame('processing', $period->status);
        $this->assertSame('auto', $period->generated_by);
        $this->assertSame(Employee::where('status', 'active')->count(), $period->payrollItems()->count());
        Notification::assertSentTo($this->admin(), PayrollGenerated::class);

        // Not generated twice.
        $this->assertSame([], app(PayrollAutomation::class)->tick()['generated']);
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

    public function test_hr_cannot_change_automation_settings(): void
    {
        $hr = User::where('username', 'brite-hr')->firstOrFail();
        $this->actingAs($hr)->put('/payroll/settings', ['auto_enabled' => 1, 'pay_date_offset_days' => 5, 'generate_delay_days' => 1])->assertForbidden();
        $this->assertFalse(PayrollAutomation::enabled());
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
