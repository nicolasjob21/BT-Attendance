<?php

namespace Tests\Feature;

use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function login(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    public function test_employee_can_view_self_service_pages(): void
    {
        $this->login('tech@brite-tsi.com');

        foreach ([
            '/dashboard', '/attendance', '/attendance/logs',
            '/leave', '/leave/create', '/overtime', '/overtime/create',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_employee_is_blocked_from_management_pages(): void
    {
        $this->login('tech@brite-tsi.com');

        $this->get('/employees')->assertForbidden();
        $this->get('/payroll')->assertForbidden();
    }

    public function test_hr_can_access_management_pages(): void
    {
        $this->login('hr@brite-tsi.com');

        $this->get('/employees')->assertOk();
        $this->get('/payroll')->assertOk();
    }

    public function test_hr_can_run_payroll_and_view_payslip(): void
    {
        $this->login('hr@brite-tsi.com');
        $period = PayrollPeriod::first();

        $this->post("/payroll/{$period->id}/generate")->assertRedirect();

        $item = $period->payrollItems()->first();
        $this->assertNotNull($item, 'Payroll items were generated');
        $this->assertGreaterThan(0, $item->net_pay);

        $this->get("/payroll/item/{$item->id}")->assertOk();
    }

    public function test_admin_sees_everything(): void
    {
        $this->login('admin@brite-tsi.com');

        foreach (['/dashboard', '/employees', '/payroll', '/attendance', '/leave', '/overtime'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
