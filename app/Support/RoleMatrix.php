<?php

namespace App\Support;

/**
 * The role & permission matrix — the code twin of BT-Attendance-Role-Permissions.xlsx.
 *
 * Seeders read DEFAULTS to build a fresh database; the Roles & Permissions page
 * reads PERMISSIONS for its grouping and descriptions. Changing a role's
 * permissions in the app does not touch this file — it is the starting point,
 * not a lock.
 */
final class RoleMatrix
{
    public const SUPERADMIN = 'superadmin';

    public const DEVELOPER = 'developer';

    public const ADMIN = 'admin';

    public const EMPLOYEE = 'employee';

    /** Display order everywhere roles are listed. */
    public const ROLES = [self::SUPERADMIN, self::DEVELOPER, self::ADMIN, self::EMPLOYEE];

    public const ROLE_LABELS = [
        self::SUPERADMIN => 'Super Admin (CEO)',
        self::DEVELOPER => 'Developer',
        self::ADMIN => 'Admin',
        self::EMPLOYEE => 'Employee',
    ];

    public const ROLE_DESCRIPTIONS = [
        self::SUPERADMIN => 'Owner. Every management module, including accounts and roles. Management only — no clock in/out, leave, OT or checkpoints.',
        self::DEVELOPER => 'Software / IT. Accounts, settings, locations, checkpoint configuration, reports. Kept out of money and HR decisions.',
        self::ADMIN => 'HR officer / office admin. Employees, attendance log, approvals, payroll, Check Point — plus their own attendance.',
        self::EMPLOYEE => 'Everyone else. Self-service only.',
    ];

    /**
     * Which roles each role may hand out (Employees form, User Management).
     * Nobody can assign a role above their own.
     */
    public const ASSIGNABLE = [
        self::SUPERADMIN => [self::SUPERADMIN, self::DEVELOPER, self::ADMIN, self::EMPLOYEE],
        self::DEVELOPER => [self::DEVELOPER, self::ADMIN, self::EMPLOYEE],
        self::ADMIN => [self::EMPLOYEE],
        self::EMPLOYEE => [],
    ];

    /**
     * Permissions that must stay on the superadmin role so nobody can lock
     * every administrator out of User Management.
     */
    public const LOCKED_SUPERADMIN = ['manage users', 'manage roles'];

    /**
     * permission => [module, what it unlocks, reserved?]
     * "Reserved" permissions exist so roles can be prepared for them, but no page uses them yet.
     */
    public const PERMISSIONS = [
        'clock attendance' => ['Self-service', 'Clock In / Out (selfie + GPS), My Attendance, timesheet softcopies, My Checkpoints'],
        'request leave' => ['Self-service', 'Leave page: file leave and early-leave requests'],
        'request overtime' => ['Self-service', 'Overtime page: file OT requests'],
        'view own payslip' => ['Self-service', 'Open own payslip'],

        'approve requests' => ['Approvals', 'Approve / deny leave and OT · verify 13h+ days · approve out-of-geofence punches'],
        'view team reports' => ['Attendance', "Attendance Log (everyone's time in/out) · any employee's timesheet"],

        'manage employees' => ['Employees', 'Employees list · add / edit · Excel import · activate / deactivate · project-site assignment'],
        'export employees' => ['Employees', 'Download the employee list as Excel'],

        'run payroll' => ['Payroll', 'Payroll page · generate payroll · salary history'],
        'view all payslips' => ['Payroll', "Open anyone's payslip without generating payroll"],

        'manage settings' => ['Settings', 'Umbrella for every settings page (sites, schedules, rates)'],
        'manage sites' => ['Settings', 'Locations / geofences only'],
        'manage schedules' => ['Settings', 'Work schedules only', true],
        'manage payroll rates' => ['Settings', 'Payroll Rates page: basic %, allowance %, OT premiums, SSS / PhilHealth / Pag-IBIG brackets, tax % — Super Admin only'],

        'manage users' => ['Users', 'User Management: accounts, passwords, disable / delete / restore, live status'],
        'manage roles' => ['Users', 'Roles & Permissions page: change what each role can access'],

        'view audit log' => ['System', 'Every sensitive action with user, time and IP', true],
        'view system health' => ['System', 'Scheduler, queue, storage, failed jobs', true],

        'view checkpoint module' => ['Check Point', 'Check Point sidebar entry · dashboard · campaigns · history · daily view'],
        'create checkpoint campaign' => ['Check Point', 'Create / edit draft campaigns'],
        'activate checkpoint campaign' => ['Check Point', 'Activate now / schedule a campaign'],
        'pause checkpoint campaign' => ['Check Point', 'Pause / resume an active campaign'],
        'end checkpoint campaign' => ['Check Point', 'End early · cancel · close'],
        'view checkpoint results' => ['Check Point', 'Monitoring page · checkpoint detail · stamped photos'],
        'review checkpoint exceptions' => ['Check Point', 'Classify missed / failed / pending checkpoints, mark reviewed'],
        'export checkpoint reports' => ['Check Point', 'Export campaign results to CSV'],
        'manage checkpoint settings' => ['Check Point', 'Defaults and the photo-instruction library'],
    ];

    /** Default grants per role, straight from the spreadsheet. */
    public const DEFAULTS = [
        self::SUPERADMIN => [
            'approve requests', 'view team reports',
            'manage employees', 'export employees',
            'run payroll', 'view all payslips',
            'manage settings', 'manage sites', 'manage schedules', 'manage payroll rates',
            'manage users', 'manage roles',
            'view audit log', 'view system health',
            'view checkpoint module', 'create checkpoint campaign', 'activate checkpoint campaign',
            'pause checkpoint campaign', 'end checkpoint campaign', 'view checkpoint results',
            'review checkpoint exceptions', 'export checkpoint reports', 'manage checkpoint settings',
        ],
        self::DEVELOPER => [
            'clock attendance', 'request leave', 'request overtime', 'view own payslip',
            'view team reports',
            'manage employees', 'export employees',
            'manage settings', 'manage sites', 'manage schedules',
            'manage users', 'manage roles',
            'view audit log', 'view system health',
            'view checkpoint module', 'create checkpoint campaign',
            'pause checkpoint campaign', 'end checkpoint campaign', 'view checkpoint results',
            'export checkpoint reports', 'manage checkpoint settings',
        ],
        self::ADMIN => [
            'clock attendance', 'request leave', 'request overtime', 'view own payslip',
            'approve requests', 'view team reports',
            'manage employees', 'export employees',
            'run payroll', 'view all payslips',
            'manage settings', 'manage sites', 'manage schedules',
            'view checkpoint module', 'create checkpoint campaign', 'activate checkpoint campaign',
            'pause checkpoint campaign', 'end checkpoint campaign', 'view checkpoint results',
            'review checkpoint exceptions', 'export checkpoint reports', 'manage checkpoint settings',
        ],
        self::EMPLOYEE => [
            'clock attendance', 'request leave', 'request overtime', 'view own payslip',
        ],
    ];

    /** @return array<string, array<string, array{0:string,1:string,2?:bool}>> module => [permission => meta] */
    public static function byModule(): array
    {
        $out = [];
        foreach (self::PERMISSIONS as $name => $meta) {
            $out[$meta[0]][$name] = $meta;
        }

        return $out;
    }

    public static function isReserved(string $permission): bool
    {
        return (bool) (self::PERMISSIONS[$permission][2] ?? false);
    }

    /** @return list<string> */
    public static function assignableBy(?string $role): array
    {
        return self::ASSIGNABLE[$role] ?? [];
    }
}
