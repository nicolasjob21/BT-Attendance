<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollDeduction;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Loans and missing-item charges are paid down one installment per cutoff. */
class PayrollDeductionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $hr;

    private Employee $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        PayrollPeriod::query()->delete();
        Carbon::setTestNow('2026-10-01 09:00');
        $this->admin = User::where('username', 'brite-admin')->firstOrFail();
        $this->hr = User::where('username', 'brite-hr')->firstOrFail();
        $this->tech = Employee::where('email', 'tech@brite-tsi.com')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function generate(string $date): PayrollPeriod
    {
        $period = PayrollPeriod::ensureFor(Carbon::parse($date));
        $this->actingAs($this->admin)->post("/payroll/{$period->id}/generate")->assertRedirect();

        return $period->refresh();
    }

    private function line(PayrollPeriod $period, Employee $e): PayrollItem
    {
        return PayrollItem::where('employee_id', $e->id)->where('payroll_period_id', $period->id)->firstOrFail();
    }

    public function test_loan_is_collected_per_cutoff_until_paid_and_never_twice(): void
    {
        $this->actingAs($this->admin)->post('/payroll/deductions', [
            'type' => 'loan', 'employee_id' => $this->tech->id, 'description' => 'Cash advance',
            'total_amount' => 3000, 'cutoffs' => 3, 'starts_on' => '2026-10-01',
        ])->assertRedirect(route('payroll.deductions', ['type' => 'loan']));

        $loan = PayrollDeduction::firstOrFail();
        $this->assertEquals(1000.00, (float) $loan->installment_amount);

        // Cutoff 1
        $p1 = $this->generate('2026-10-05');
        $l1 = $this->line($p1, $this->tech);
        $this->assertEquals(1000.00, (float) $l1->loan_deduction);
        $this->assertEquals(2000.00, (float) $loan->fresh()->balance);
        $this->assertEquals((float) $l1->total_deductions, (float) $l1->sss_deduction + (float) $l1->philhealth_deduction + (float) $l1->pagibig_deduction + (float) $l1->withholding_tax + (float) $l1->loan_deduction);

        // Recalculating the same cutoff must not charge again.
        $this->generate('2026-10-05');
        $this->assertEquals(1000.00, (float) $this->line($p1, $this->tech)->loan_deduction);
        $this->assertEquals(2000.00, (float) $loan->fresh()->balance);
        $this->assertSame(1, $loan->payments()->count());

        // Cutoffs 2 and 3 clear it; cutoff 4 takes nothing.
        $this->generate('2026-10-20');
        $this->assertEquals(1000.00, (float) $loan->fresh()->balance);
        $p3 = $this->generate('2026-11-05');
        $this->assertEquals(0.00, (float) $loan->fresh()->balance);
        $this->assertSame(PayrollDeduction::PAID, $loan->fresh()->status);
        $p4 = $this->generate('2026-11-20');
        $this->assertEquals(0.00, (float) $this->line($p4, $this->tech)->loan_deduction);

        // The payslip names the loan and the balance after that installment.
        $this->actingAs($this->admin)->get('/payroll/item/'.$this->line($p3, $this->tech)->id)->assertOk()
            ->assertSee('Cash advance')->assertSee('balance after: ₱0.00');
    }

    public function test_missing_item_is_split_across_the_employees_responsible(): void
    {
        $site = Site::where('type', 'project_site')->firstOrFail();
        $hrEmp = Employee::where('email', 'hr@brite-tsi.com')->firstOrFail();

        $this->actingAs($this->admin)->post('/payroll/deductions', [
            'type' => 'missing_item', 'site_id' => $site->id, 'description' => '2 × cordless drill', 'incident_date' => '2026-09-28',
            'total_amount' => 1500, 'cutoffs' => 1, 'starts_on' => '2026-10-01', 'employees' => [$this->tech->id, $hrEmp->id],
        ])->assertRedirect();

        $charges = PayrollDeduction::where('type', 'missing_item')->get();
        $this->assertCount(2, $charges);
        $this->assertEquals([750.00, 750.00], $charges->pluck('total_amount')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(1, $charges->pluck('group_id')->unique()->count());

        $p1 = $this->generate('2026-10-05');
        $this->assertEquals(750.00, (float) $this->line($p1, $this->tech)->missing_item_deduction);
        $this->assertEquals(750.00, (float) $this->line($p1, $hrEmp)->missing_item_deduction);
        $this->assertEquals(0.00, (float) $this->line($p1, $this->tech)->loan_deduction);

        $this->actingAs($this->admin)->get('/payroll/item/'.$this->line($p1, $this->tech)->id)->assertOk()
            ->assertSee('Missing item')->assertSee('2 × cordless drill')->assertSee($site->name);

        // The register export carries the new columns.
        $this->actingAs($this->hr)->get("/payroll/{$p1->id}/export")->assertOk();
    }

    public function test_recording_a_deduction_refreshes_an_already_computed_unreleased_cutoff(): void
    {
        $p1 = $this->generate('2026-10-05');
        $this->assertEquals(0.00, (float) $this->line($p1, $this->tech)->loan_deduction);

        $this->actingAs($this->admin)->post('/payroll/deductions', [
            'type' => 'loan', 'employee_id' => $this->tech->id, 'description' => 'Advance', 'total_amount' => 1000, 'cutoffs' => 2, 'starts_on' => '2026-10-01',
        ])->assertRedirect();
        $this->assertEquals(500.00, (float) $this->line($p1, $this->tech)->loan_deduction);
        $loan = PayrollDeduction::firstOrFail();
        $this->assertEquals(500.00, (float) $loan->balance);

        // Cancelling the rest gives this cutoff's installment back too (still unreleased).

        $this->actingAs($this->admin)->post("/payroll/deductions/{$loan->id}/cancel", ['reason' => 'Waived'])->assertRedirect();
        $this->assertSame(PayrollDeduction::CANCELLED, $loan->fresh()->status);
        $this->assertEquals(0.00, (float) $this->line($p1, $this->tech)->loan_deduction);
    }

    public function test_only_the_super_admin_records_loans_and_charges(): void
    {
        $this->actingAs($this->hr)->get('/payroll/deductions')->assertOk()->assertSee('Loans')->assertDontSee('Add loan or missing item');
        $this->actingAs($this->hr)->post('/payroll/deductions', [
            'type' => 'loan', 'employee_id' => $this->tech->id, 'description' => 'x', 'total_amount' => 100, 'cutoffs' => 1, 'starts_on' => '2026-10-01',
        ])->assertForbidden();
        $this->actingAs($this->admin)->get('/payroll/deductions')->assertOk()->assertSee('Add loan or missing item')->assertSee('What is this?');
        $this->actingAs(User::where('username', 'brite-tech')->firstOrFail())->get('/payroll/deductions')->assertForbidden();
    }
}
