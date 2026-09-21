<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Schedule;
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
        $this->assertSame('brite-juan', $user->username); // generated: company prefix + first name
        $this->assertTrue($user->hasRole('employee'));
        $this->assertDatabaseHas('employees', ['email' => 'juan@brite-tsi.com', 'schedule_id' => Schedule::where('is_flexible', false)->value('id')]);
        $this->assertNotNull($user->employee->employee_no);
    }

    public function test_generated_usernames_get_a_suffix_when_the_first_name_is_taken(): void
    {
        $this->actAsHr();

        foreach (['one', 'two'] as $i) {
            $this->post('/employees', [
                'first_name' => 'Juan',
                'last_name' => "Number {$i}",
                'email' => "juan.{$i}@brite-tsi.com",
                'employee_type' => 'admin',
                'role' => 'employee',
                'monthly_salary' => 20000,
                'status' => 'active',
            ])->assertRedirect(route('employees.index'));
        }

        $this->assertDatabaseHas('users', ['email' => 'juan.one@brite-tsi.com', 'username' => 'brite-juan']);
        $this->assertDatabaseHas('users', ['email' => 'juan.two@brite-tsi.com', 'username' => 'brite-juan2']);
    }

    public function test_search_matches_full_names_numbers_and_usernames(): void
    {
        $hr = User::where('username', 'brite-hr')->firstOrFail();
        $tech = Employee::where('email', 'tech@brite-tsi.com')->firstOrFail(); // Technical Staff, brite-tech
        $full = $tech->first_name.' '.$tech->last_name;

        foreach ([$tech->first_name, $tech->last_name, $full, strtolower($full), $tech->last_name.' '.$tech->first_name, $tech->employee_no, 'brite-tech'] as $term) {
            $this->actingAs($hr)->get('/employees?search='.urlencode($term))->assertOk()->assertSee($full);
        }
        // Every word must match: a name that exists + a word that does not.
        $this->actingAs($hr)->get('/employees?search='.urlencode($tech->first_name.' zzz'))->assertOk()->assertDontSee($full);

        // The Attendance Log uses the same search.
        $this->actingAs($hr)->get('/attendance/monitor?search='.urlencode($full))->assertOk()->assertSee($full);
    }

    public function test_hr_can_choose_a_username_and_it_is_stored_lowercase(): void
    {
        $this->actAsHr();

        $this->post('/employees', [
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'username' => 'Brite-Juan',
            'email' => 'job@brite-tsi.com',
            'employee_type' => 'admin',
            'role' => 'employee',
            'monthly_salary' => 20000,
            'status' => 'active',
        ])->assertRedirect(route('employees.index'));

        $this->assertDatabaseHas('users', ['email' => 'job@brite-tsi.com', 'username' => 'brite-juan']);

        // A second account cannot claim the same username.
        $this->post('/employees', [
            'first_name' => 'Juanita',
            'last_name' => 'Cruz',
            'username' => 'brite-juan',
            'email' => 'jobelle@brite-tsi.com',
            'employee_type' => 'admin',
            'role' => 'employee',
            'monthly_salary' => 20000,
            'status' => 'active',
        ])->assertSessionHasErrors('username');
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
        $this->assertFalse($user->hasRole('superadmin'));
    }

    public function test_hr_can_bulk_import_employees_from_csv(): void
    {
        $this->actAsHr();

        $csv = "first_name,last_name,email,employee_type,monthly_salary\n"
            ."Ana,Reyes,ana@brite-tsi.com,technical,26000\n"
            ."Ben,Lim,ben@brite-tsi.com,admin,21000\n"
            ."Bad,Row,not-an-email,admin,15000\n"; // should be skipped

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
