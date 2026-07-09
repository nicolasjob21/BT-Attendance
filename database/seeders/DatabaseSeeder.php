<?php

namespace Database\Seeders;

use App\Models\ContributionRate;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\Schedule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seedRolesAndPermissions();
        $schedules = $this->seedSchedules();
        $this->seedSites();
        $this->seedLeaveTypes();
        $this->seedContributionRates();
        $this->seedPayrollPeriods();
        $this->seedDemoUsers($schedules);
    }

    private function seedRolesAndPermissions(): void
    {
        $permissions = [
            'clock attendance',
            'request leave',
            'request overtime',
            'view own payslip',
            'approve requests',      // leave & OT
            'view team reports',
            'manage employees',
            'run payroll',
            'manage settings',       // schedules, sites, leave types, contribution rates
            'manage users',          // accounts & roles
        ];
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'web');
        }

        // Self-service permissions every employee has.
        $selfService = ['clock attendance', 'request leave', 'request overtime', 'view own payslip'];

        // Three roles only. HR and the CEO/super-admin get the management screens
        // (Attendance Log, Employees, Payroll); employees get self-service only.
        $roles = [
            'employee' => $selfService,
            'hr' => array_merge($selfService, [
                'approve requests', 'view team reports', 'manage employees', 'run payroll', 'manage settings',
            ]),
            'superadmin' => $permissions, // CEO — full access, incl. managing users
        ];

        foreach ($roles as $name => $perms) {
            Role::findOrCreate($name, 'web')->syncPermissions($perms);
        }
    }

    private function seedSchedules(): array
    {
        $admin = Schedule::create([
            'name' => 'Admin (8:30 AM – 5:30 PM)',
            'time_in' => '08:30:00',
            'time_out' => '17:30:00',
            'grace_minutes' => 15,
            'is_flexible' => false,
        ]);

        $flexible = Schedule::create([
            'name' => 'Technical (Flexible)',
            'time_in' => null,
            'time_out' => null,
            'grace_minutes' => 0,
            'is_flexible' => true,
        ]);

        return ['admin' => $admin, 'flexible' => $flexible];
    }

    private function seedSites(): void
    {
        Site::create([
            'name' => 'Brite TSI — Head Office',
            'address' => 'Metro Manila, Philippines',
            'latitude' => 14.5995000,
            'longitude' => 120.9842000,
            'geofence_radius_m' => 150,
            'is_headquarters' => true,
        ]);

        Site::create([
            'name' => 'Sample Client Site',
            'client_name' => 'ACME Corp',
            'address' => 'Quezon City, Philippines',
            'latitude' => 14.6760000,
            'longitude' => 121.0437000,
            'geofence_radius_m' => 200,
        ]);
    }

    private function seedLeaveTypes(): void
    {
        $types = [
            ['Service Incentive Leave', 'SIL', 5, true],
            ['Vacation Leave', 'VL', 0, true],
            ['Sick Leave', 'SL', 0, true],
            ['Maternity Leave', 'ML', 105, true],
            ['Paternity Leave', 'PL', 7, true],
            ['Solo Parent Leave', 'SPL', 7, true],
            ['Special Leave for Women', 'SLW', 60, true],
            ['Bereavement Leave', 'BL', 3, true],
        ];
        foreach ($types as [$name, $code, $days, $paid]) {
            LeaveType::create([
                'name' => $name,
                'code' => $code,
                'default_days_per_year' => $days,
                'is_paid' => $paid,
            ]);
        }
    }

    private function seedContributionRates(): void
    {
        $year = 2026;

        // SSS: 5% employee, 10% employer + EC, MSC ₱5,000–₱35,000
        ContributionRate::create([
            'contribution_type' => 'sss', 'min_salary' => 5000, 'max_salary' => 35000,
            'employee_rate' => 0.0500, 'employer_rate' => 0.1000, 'ec_amount' => 30, 'effective_year' => $year,
        ]);

        // PhilHealth: 2.5% each, floor ₱10,000 ceiling ₱100,000
        ContributionRate::create([
            'contribution_type' => 'philhealth', 'min_salary' => 10000, 'max_salary' => 100000,
            'employee_rate' => 0.0250, 'employer_rate' => 0.0250, 'ec_amount' => 0, 'effective_year' => $year,
        ]);

        // Pag-IBIG: 1% employee if ≤₱1,500; 2% above. Employer 2%. Fund-salary cap ₱10,000.
        ContributionRate::create([
            'contribution_type' => 'pagibig', 'min_salary' => 0, 'max_salary' => 1500,
            'employee_rate' => 0.0100, 'employer_rate' => 0.0200, 'ec_amount' => 0, 'effective_year' => $year,
        ]);
        ContributionRate::create([
            'contribution_type' => 'pagibig', 'min_salary' => 1500.01, 'max_salary' => null,
            'employee_rate' => 0.0200, 'employer_rate' => 0.0200, 'ec_amount' => 0, 'effective_year' => $year,
        ]);
    }

    private function seedPayrollPeriods(): void
    {
        PayrollPeriod::create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-15',
            'pay_date' => '2026-07-20',
            'cutoff_type' => 'first_half',
            'status' => 'open',
        ]);
    }

    private function seedDemoUsers(array $schedules): void
    {
        // [name, email, role, employee_type, schedule, monthly_salary]
        $people = [
            ['CEO / Super Admin', 'admin@brite-tsi.com', 'superadmin', 'admin', 'admin', 80000],
            ['HR Officer', 'hr@brite-tsi.com', 'hr', 'admin', 'admin', 35000],
            ['Technical Staff', 'tech@brite-tsi.com', 'employee', 'technical', 'flexible', 25000],
        ];

        $n = 1;
        foreach ($people as [$name, $email, $role, $type, $sched, $salary]) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);
            $user->assignRole($role);

            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

            $employee = Employee::create([
                'user_id' => $user->id,
                'employee_no' => 'EMP-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'employee_type' => $type,
                'schedule_id' => $schedules[$sched]->id,
                'supervisor_id' => null,
                'monthly_salary' => $salary,
                'daily_rate' => round($salary / 22, 2),
                'date_hired' => '2025-01-06',
                'status' => 'active',
            ]);

            $n++;
        }
    }
}
