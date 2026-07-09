<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function actAsHr(): void
    {
        $this->actingAs(User::where('email', 'hr@brite-tsi.com')->firstOrFail());
    }

    public function test_hr_can_create_an_employee_with_a_login_account(): void
    {
        $this->actAsHr();

        $this->post('/employees', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@brite-tsi.com',
            'employee_type' => 'technical',
            'role' => 'employee',
            'monthly_salary' => 28000,
            'status' => 'active',
            'password' => 'secret123',
        ])->assertRedirect(route('employees.index'));

        $user = User::where('email', 'juan@brite-tsi.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('employee'));
        $this->assertDatabaseHas('employees', ['email' => 'juan@brite-tsi.com', 'employee_type' => 'technical']);
        $this->assertNotNull($user->employee->employee_no);
    }

    public function test_hr_cannot_grant_admin_role(): void
    {
        $this->actAsHr();

        $this->post('/employees', [
            'first_name' => 'Sneaky',
            'last_name' => 'User',
            'email' => 'sneaky@brite-tsi.com',
            'employee_type' => 'admin',
            'role' => 'admin', // should be downgraded
            'monthly_salary' => 30000,
            'status' => 'active',
        ])->assertRedirect();

        $user = User::where('email', 'sneaky@brite-tsi.com')->first();
        $this->assertTrue($user->hasRole('employee'));
        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_hr_can_bulk_import_employees_from_csv(): void
    {
        $this->actAsHr();

        $csv = "first_name,last_name,email,employee_type,monthly_salary\n"
            . "Ana,Reyes,ana@brite-tsi.com,technical,26000\n"
            . "Ben,Lim,ben@brite-tsi.com,admin,21000\n"
            . "Bad,Row,not-an-email,admin,15000\n"; // should be skipped

        $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

        $this->post('/employees/import', [
            'file' => $file,
            'default_password' => 'Brite@2026',
        ])->assertRedirect(route('employees.index'));

        $this->assertDatabaseHas('users', ['email' => 'ana@brite-tsi.com']);
        $this->assertDatabaseHas('users', ['email' => 'ben@brite-tsi.com']);
        $this->assertDatabaseMissing('users', ['email' => 'not-an-email']);
    }

    public function test_hr_can_toggle_employee_status(): void
    {
        $this->actAsHr();
        $employee = Employee::where('email', 'tech@brite-tsi.com')->firstOrFail();

        $this->patch("/employees/{$employee->id}/status")->assertRedirect();
        $this->assertEquals('inactive', $employee->fresh()->status);

        $this->patch("/employees/{$employee->id}/status")->assertRedirect();
        $this->assertEquals('active', $employee->fresh()->status);
    }

    public function test_plain_employee_cannot_create_employees(): void
    {
        $this->actingAs(User::where('email', 'tech@brite-tsi.com')->firstOrFail());
        $this->get('/employees/create')->assertForbidden();
        $this->post('/employees', [])->assertForbidden();
    }
}
