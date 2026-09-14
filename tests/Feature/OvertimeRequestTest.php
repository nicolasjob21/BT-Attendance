<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Overtime is requested in advance with a reason, decided by Admin/HR, and
 * the actual hours are derived from attendance afterwards.
 */
class OvertimeRequestTest extends TestCase
{
    use RefreshDatabase;

    private Employee $hrEmp;   // fixed 8:30–17:30 schedule
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
        Carbon::setTestNow('2026-09-14 10:00:00'); // a Monday

        $this->hrEmp = Employee::where('email', 'hr@brite-tsi.com')->firstOrFail();
        $this->admin = User::where('email', 'admin@brite-tsi.com')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function request(array $overrides = [])
    {
        return $this->actingAs($this->hrEmp->user)->post('/overtime', array_merge([
            'ot_date' => '2026-09-14',
            'planned_start' => '17:30',
            'planned_end' => '20:30',
            'reason' => 'Finish cable termination at Project Site A for tomorrow\'s inspection.',
        ], $overrides));
    }

    public function test_employee_requests_overtime_in_advance_with_a_reason(): void
    {
        $this->request()->assertRedirect(route('overtime.index'));

        $ot = OvertimeRequest::firstOrFail();
        $this->assertSame('pending', $ot->status);
        $this->assertEquals(3.0, (float) $ot->requested_hours);
        $this->assertNull($ot->hours, 'actual hours are not known until the employee clocks out');
        $this->assertSame('regular', $ot->ot_type);
        $this->assertSame('5:30 PM – 8:30 PM', $ot->plannedWindow());

        Notification::assertSentTo($this->admin, ApprovalRequested::class);
    }

    public function test_reason_is_required(): void
    {
        $this->request(['reason' => ''])->assertSessionHasErrors('reason');
        $this->request(['reason' => 'ot'])->assertSessionHasErrors('reason');
        $this->assertSame(0, OvertimeRequest::count());
    }

    public function test_cannot_request_overtime_for_the_past(): void
    {
        $this->request(['ot_date' => '2026-09-10'])->assertSessionHasErrors('ot_date');
        // Up to 3 days back is tolerated, so weekend site work can be filed on Monday.
        $this->request(['ot_date' => '2026-09-12'])->assertSessionHasNoErrors(); // Saturday
    }

    public function test_weekend_site_work_is_a_full_rest_day_shift_at_130_percent(): void
    {
        $tech = Employee::where('email', 'tech@brite-tsi.com')->firstOrFail(); // flexible schedule

        // Saturday Sep 12, planned 8–5, filed on Monday.
        $this->actingAs($tech->user)->post('/overtime', [
            'ot_date' => '2026-09-12',
            'planned_start' => '08:00',
            'planned_end' => '17:00',
            'reason' => 'Saturday deployment at Project Site A — client shutdown window for panel installation.',
        ])->assertSessionHasNoErrors();

        $ot = OvertimeRequest::firstOrFail();
        $this->assertSame('rest_day', $ot->ot_type);
        $this->assertEquals(9.0, (float) $ot->requested_hours);

        // Worked 8:00 → 16:30 that Saturday: every minute is rest-day OT.
        $tech->attendanceLogs()->create(['log_type' => 'time_in', 'logged_at' => '2026-09-12 08:00:00']);
        $tech->attendanceLogs()->create(['log_type' => 'time_out', 'logged_at' => '2026-09-12 16:30:00']);

        // Approving a late filing derives the hours immediately.
        $this->actingAs($this->admin)->post(route('overtime.approve', $ot))->assertRedirect();
        $ot->refresh();
        $this->assertEquals(8.5, (float) $ot->hours);
        $this->assertEquals(8.5, $ot->payableHours());
        $this->assertSame(1.30, $ot->multiplier());
        $this->assertSame('Sep 16 – Sep 30 payroll', $ot->payoutCutoffLabel());
    }

    public function test_weekend_monitor_flags_site_work_without_a_request(): void
    {
        $tech = Employee::where('email', 'tech@brite-tsi.com')->firstOrFail();
        $tech->attendanceLogs()->create(['log_type' => 'time_in', 'logged_at' => '2026-09-12 08:00:00']);
        $tech->attendanceLogs()->create(['log_type' => 'time_out', 'logged_at' => '2026-09-12 16:30:00']);

        $this->actingAs($this->admin)
            ->get(route('attendance.monitor', ['date' => '2026-09-12']))
            ->assertOk()
            ->assertSee('No OT request')
            ->assertSee('Day off')
            ->assertDontSee('>Absent<', false);
    }

    public function test_only_one_open_request_per_date(): void
    {
        $this->request()->assertSessionHasNoErrors();
        $this->request()->assertSessionHasErrors('ot_date');
    }

    public function test_window_crossing_midnight_is_counted_correctly(): void
    {
        $this->request(['planned_start' => '22:00', 'planned_end' => '02:00'])->assertSessionHasNoErrors();
        $this->assertEquals(4.0, (float) OvertimeRequest::firstOrFail()->requested_hours);
    }

    public function test_admin_can_approve_with_remarks_and_employee_is_notified(): void
    {
        $this->request();
        $ot = OvertimeRequest::firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('overtime.approve', $ot), ['remarks' => 'Approved, keep it under 3h.'])
            ->assertRedirect();

        $ot->refresh();
        $this->assertSame('approved', $ot->status);
        $this->assertSame('Approved, keep it under 3h.', $ot->admin_remarks);
        $this->assertSame($this->admin->employee->id, $ot->approved_by);
        $this->assertNull($ot->hours, 'not clocked out yet');

        Notification::assertSentTo($this->hrEmp->user, \App\Notifications\RequestReviewed::class);
    }

    public function test_admin_can_deny(): void
    {
        $this->request();
        $ot = OvertimeRequest::firstOrFail();

        $this->actingAs($this->admin)->post(route('overtime.deny', $ot), ['remarks' => 'Not needed this week.'])->assertRedirect();

        $this->assertSame('denied', $ot->fresh()->status);
        // A decided request can't be re-decided.
        $this->actingAs($this->admin)->post(route('overtime.approve', $ot))->assertStatus(422);
    }

    public function test_employee_cannot_decide_requests(): void
    {
        $this->request();
        $ot = OvertimeRequest::firstOrFail();

        $this->actingAs(Employee::where('email', 'tech@brite-tsi.com')->firstOrFail()->user)
            ->post(route('overtime.approve', $ot))
            ->assertForbidden();
    }

    public function test_actual_hours_are_derived_from_attendance_and_capped_at_the_approved_plan(): void
    {
        $this->request(); // planned 3h
        $ot = OvertimeRequest::firstOrFail();
        $this->actingAs($this->admin)->post(route('overtime.approve', $ot));

        // Worked 8:30 → 21:30: 4h past the 17:30 scheduled out.
        $this->hrEmp->attendanceLogs()->create(['log_type' => 'time_in', 'logged_at' => '2026-09-14 08:30:00']);
        $this->hrEmp->attendanceLogs()->create(['log_type' => 'time_out', 'logged_at' => '2026-09-14 21:30:00']);

        // Viewing the list syncs the actual hours.
        $this->actingAs($this->hrEmp->user)->get('/overtime')->assertOk()->assertDontSee('awaiting clock-out');
        $ot->refresh();

        $this->assertEquals(4.0, (float) $ot->hours);
        $this->assertEquals(3.0, $ot->payableHours(), 'paid hours are capped at the approved plan');
        $this->assertNotNull($ot->hours_synced_at);
    }

    public function test_overtime_is_paid_one_cutoff_in_arrears(): void
    {
        $this->request(); // OT on Sep 14 (first half)
        $ot = OvertimeRequest::firstOrFail();
        $this->actingAs($this->admin)->post(route('overtime.approve', $ot));
        $this->assertSame('Sep 16 – Sep 30 payroll', $ot->fresh()->payoutCutoffLabel());

        $firstHalf = PayrollPeriod::create([
            'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'pay_date' => '2026-09-20',
            'cutoff_type' => 'first_half', 'status' => 'open',
        ]);
        $secondHalf = PayrollPeriod::create([
            'period_start' => '2026-09-16', 'period_end' => '2026-09-30', 'pay_date' => '2026-10-05',
            'cutoff_type' => 'second_half', 'status' => 'open',
        ]);
        $this->assertSame('Aug 16 – Aug 31', $firstHalf->overtimeWindowLabel());
        $this->assertSame('Sep 1 – Sep 15', $secondHalf->overtimeWindowLabel());

        // Worked 8:30 → 21:30 on Sep 14: 4h actual, 3h approved.
        $this->hrEmp->attendanceLogs()->create(['log_type' => 'time_in', 'logged_at' => '2026-09-14 08:30:00']);
        $this->hrEmp->attendanceLogs()->create(['log_type' => 'time_out', 'logged_at' => '2026-09-14 21:30:00']);
        $this->actingAs($this->hrEmp->user)->get('/overtime'); // sync actual hours

        $hourly = (float) $this->hrEmp->daily_rate / 8;

        // Not on the Sep 1–15 run (that run pays Aug 16–31 OT)…
        $this->assertEquals(0, (float) app(PayrollCalculator::class)->calculate($this->hrEmp, $firstHalf)->overtime_pay);
        // …but on the Sep 16–30 run, capped at the 3h approved.
        $this->assertEqualsWithDelta(3 * $hourly * 1.25, (float) app(PayrollCalculator::class)->calculate($this->hrEmp, $secondHalf)->overtime_pay, 0.01);
    }

    public function test_second_half_overtime_is_paid_next_month(): void
    {
        $this->request(['ot_date' => '2026-09-20']);
        $ot = OvertimeRequest::firstOrFail();
        $this->assertSame('Oct 1 – Oct 15 payroll', $ot->payoutCutoffLabel());

        $octFirstHalf = PayrollPeriod::create([
            'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'pay_date' => '2026-10-20',
            'cutoff_type' => 'first_half', 'status' => 'open',
        ]);
        $this->assertSame('Sep 16 – Sep 30', $octFirstHalf->overtimeWindowLabel());
    }
}
