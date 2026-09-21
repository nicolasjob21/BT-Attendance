<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\CheckpointCampaignController;
use App\Http\Controllers\CheckpointResultController;
use App\Http\Controllers\CheckpointSettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeCheckpointController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PayrollDeductionController;
use App\Http\Controllers\PayrollRatesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectAssignmentController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // --- Notifications (all authenticated staff) ---
    Route::get('/notifications/{id}', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    // --- Self-service (roles with attendance: Admin, Developer, Employee — not Superadmin) ---
    Route::middleware('permission:clock attendance')->group(function () {
        Route::get('/attendance', [AttendanceController::class, 'create'])->name('attendance.create');
        Route::post('/attendance', [AttendanceController::class, 'store'])->name('attendance.store');
        Route::get('/attendance/logs', [AttendanceController::class, 'index'])->name('attendance.index');
    });
    // Softcopy and timesheet: own records, or anyone's with `view team reports` (checked in the controller).
    Route::get('/attendance/{id}/softcopy/{type}', [AttendanceController::class, 'softCopy'])
        ->whereIn('type', ['in', 'out'])->name('attendance.softcopy');
    Route::get('/attendance/timesheet/{employee}', [AttendanceController::class, 'timesheet'])->name('attendance.timesheet');

    // --- Attendance monitor: everyone's time in/out by date (Dept. Head, HR, Admin) ---
    Route::get('/attendance/monitor', [AttendanceController::class, 'monitor'])
        ->middleware('permission:view team reports')->name('attendance.monitor');

    // Leave and overtime lists double as the approval queue, so approvers get in too.
    Route::middleware('permission:request leave|approve requests')->group(function () {
        Route::get('/leave', [LeaveController::class, 'index'])->name('leave.index');
    });
    Route::middleware('permission:request leave')->group(function () {
        Route::get('/leave/create', [LeaveController::class, 'create'])->name('leave.create');
        Route::post('/leave', [LeaveController::class, 'store'])->name('leave.store');
        Route::get('/leave/early', [LeaveController::class, 'earlyCreate'])->name('leave.early.create');
        Route::post('/leave/early', [LeaveController::class, 'earlyStore'])->name('leave.early.store');
    });

    Route::middleware('permission:request overtime|approve requests')->group(function () {
        Route::get('/overtime', [OvertimeController::class, 'index'])->name('overtime.index');
    });
    Route::middleware('permission:request overtime')->group(function () {
        Route::get('/overtime/create', [OvertimeController::class, 'create'])->name('overtime.create');
        Route::post('/overtime', [OvertimeController::class, 'store'])->name('overtime.store');
    });

    // Own payslips (released periods only), or anyone's with `view all payslips` / `run payroll` (checked in the controller).
    Route::get('/my-payslips', [PayrollController::class, 'mine'])->middleware('permission:view own payslip')->name('payroll.mine');
    Route::get('/payroll/item/{item}', [PayrollController::class, 'show'])->name('payroll.show');

    // --- Approvals (Dept. Head, HR, Admin) ---
    Route::middleware('permission:approve requests')->group(function () {
        Route::post('/leave/{leave}/approve', [LeaveController::class, 'approve'])->name('leave.approve');
        Route::post('/leave/{leave}/deny', [LeaveController::class, 'deny'])->name('leave.deny');
        Route::post('/overtime/{overtime}/approve', [OvertimeController::class, 'approve'])->name('overtime.approve');
        Route::post('/overtime/{overtime}/deny', [OvertimeController::class, 'deny'])->name('overtime.deny');

        // Verify an unusually long day (13h+) flagged on the attendance monitor.
        Route::post('/attendance/{log}/verify', [AttendanceController::class, 'verify'])->name('attendance.verify');
        // Approve/reject a punch made outside every authorized geofence (or with no/weak GPS).
        Route::post('/attendance/{log}/verify-location', [AttendanceController::class, 'verifyLocation'])->name('attendance.verify-location');
    });

    // --- Employee management (HR, Admin) ---
    Route::middleware('permission:manage employees')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('/employees/import', [EmployeeController::class, 'importForm'])->name('employees.import');
        Route::get('/employees/import/template', [EmployeeController::class, 'importTemplate'])->name('employees.import.template');
        Route::get('/employees/export', [EmployeeController::class, 'export'])
            ->middleware('permission:export employees')->name('employees.export');
        Route::post('/employees/import', [EmployeeController::class, 'import'])->name('employees.import.store');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::patch('/employees/{employee}/status', [EmployeeController::class, 'toggleStatus'])->name('employees.status');

        // Project site deployment (which site an employee is assigned to).
        Route::post('/employees/{employee}/assignments', [ProjectAssignmentController::class, 'store'])->name('employees.assignments.store');
        Route::patch('/employees/{employee}/assignments/{assignment}/end', [ProjectAssignmentController::class, 'end'])->name('employees.assignments.end');
    });

    // --- User management: login accounts, roles, live presence (Super Admin, Developer) ---
    Route::middleware('permission:manage users')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/presence', [UserController::class, 'presence'])->name('users.presence');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/disabled', [UserController::class, 'toggleDisabled'])->name('users.disabled');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
    });

    // --- Payroll rates: the company-wide percentages behind everyone's payroll ---
    Route::middleware('permission:manage payroll rates')->group(function () {
        Route::get('/payroll/rates', [PayrollRatesController::class, 'index'])->name('payroll.rates');
        Route::put('/payroll/rates', [PayrollRatesController::class, 'update'])->name('payroll.rates.update');
    });

    // --- Settings: attendance locations / geofences (Admin, Developer) ---
    Route::middleware('permission:manage settings|manage sites')->group(function () {
        Route::get('/settings/sites', [SiteController::class, 'index'])->name('sites.index');
        Route::get('/settings/sites/create', [SiteController::class, 'create'])->name('sites.create');
        Route::get('/settings/sites/resolve-link', [SiteController::class, 'resolveLink'])->name('sites.resolve-link');
        Route::post('/settings/sites', [SiteController::class, 'store'])->name('sites.store');
        Route::get('/settings/sites/{site}/edit', [SiteController::class, 'edit'])->name('sites.edit');
        Route::put('/settings/sites/{site}', [SiteController::class, 'update'])->name('sites.update');
        Route::patch('/settings/sites/{site}/status', [SiteController::class, 'setStatus'])->name('sites.status');
    });

    // --- Payroll (HR, Admin) ---
    Route::middleware('permission:run payroll')->group(function () {
        Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
        Route::post('/payroll/{period}/generate', [PayrollController::class, 'generate'])->name('payroll.generate');
        Route::post('/payroll/periods', [PayrollController::class, 'createPeriod'])->name('payroll.periods.create');
        Route::post('/payroll/{period}/close', [PayrollController::class, 'close'])->name('payroll.close');
        Route::post('/payroll/{period}/release', [PayrollController::class, 'release'])->name('payroll.release');
        Route::get('/payroll/{period}/export', [PayrollController::class, 'export'])->name('payroll.export');
        Route::get('/payroll/{period}/print', [PayrollController::class, 'printBatch'])->name('payroll.print');
        // Loans & missing-item charges (balances paid down per cutoff).
        Route::get('/payroll/deductions', [PayrollDeductionController::class, 'index'])->name('payroll.deductions');
        Route::post('/payroll/deductions', [PayrollDeductionController::class, 'store'])->name('payroll.deductions.store');
        Route::put('/payroll/deductions/{deduction}', [PayrollDeductionController::class, 'update'])->name('payroll.deductions.update');
        Route::post('/payroll/deductions/{deduction}/cancel', [PayrollDeductionController::class, 'cancel'])->name('payroll.deductions.cancel');
        Route::get('/payroll/lines/{item}/edit', [PayrollController::class, 'edit'])->name('payroll.lines.edit');
        Route::put('/payroll/lines/{item}', [PayrollController::class, 'update'])->name('payroll.lines.update');
        Route::post('/payroll/lines/{item}/reset', [PayrollController::class, 'reset'])->name('payroll.lines.reset');
        Route::get('/employees/{employee}/salary-history', [PayrollController::class, 'salaryHistory'])->name('employees.salary-history');
    });

    // --- Check Point: employee side (respond to the shared checkpoint) — same roles that clock in ---
    Route::middleware('permission:clock attendance')->group(function () {
        Route::get('/my-checkpoints', [EmployeeCheckpointController::class, 'index'])->name('my-checkpoints.index');
        Route::get('/my-checkpoints/active', [EmployeeCheckpointController::class, 'active'])->name('my-checkpoints.active');
        Route::get('/my-checkpoints/{checkpoint}', [EmployeeCheckpointController::class, 'show'])->name('my-checkpoints.show');
        Route::post('/my-checkpoints/{checkpoint}', [EmployeeCheckpointController::class, 'submit'])->name('my-checkpoints.submit');
        Route::post('/my-checkpoints/{checkpoint}/issue', [EmployeeCheckpointController::class, 'reportIssue'])->name('my-checkpoints.issue');
        Route::post('/my-checkpoints/{checkpoint}/explain', [EmployeeCheckpointController::class, 'explain'])->name('my-checkpoints.explain');
    });
    // Private checkpoint photo: owner or anyone with `view checkpoint results` (checked in the controller).
    Route::get('/checkpoint-photos/{checkpoint}', [CheckpointResultController::class, 'photo'])->name('checkpoints.photo');

    // --- Check Point: management (HR, Admin) — every action is permission-gated server-side ---
    Route::prefix('checkpoints')->name('checkpoints.')->middleware('permission:view checkpoint module')->group(function () {
        Route::get('/', [CheckpointCampaignController::class, 'index'])->name('index');
        Route::get('/history', [CheckpointCampaignController::class, 'history'])->name('history');
        Route::get('/daily', [CheckpointCampaignController::class, 'daily'])->name('daily');

        Route::middleware('permission:create checkpoint campaign')->group(function () {
            Route::get('/create', [CheckpointCampaignController::class, 'create'])->name('create');
            Route::post('/', [CheckpointCampaignController::class, 'store'])->name('store');
            Route::get('/campaigns/{campaign}/edit', [CheckpointCampaignController::class, 'edit'])->name('edit');
            Route::put('/campaigns/{campaign}', [CheckpointCampaignController::class, 'update'])->name('update');
        });

        // Monitoring page + live counters.
        Route::get('/campaigns/{campaign}', [CheckpointCampaignController::class, 'show'])->name('show');
        Route::get('/campaigns/{campaign}/status', [CheckpointCampaignController::class, 'status'])->name('status');

        Route::middleware('permission:activate checkpoint campaign')->group(function () {
            Route::post('/campaigns/{campaign}/activate', [CheckpointCampaignController::class, 'activate'])->name('activate');
            Route::post('/campaigns/{campaign}/schedule', [CheckpointCampaignController::class, 'schedule'])->name('schedule');
            Route::post('/campaigns/{campaign}/schedule-random', [CheckpointCampaignController::class, 'scheduleRandom'])->name('schedule-random');
        });
        Route::middleware('permission:pause checkpoint campaign')->group(function () {
            Route::post('/campaigns/{campaign}/pause', [CheckpointCampaignController::class, 'pause'])->name('pause');
            Route::post('/campaigns/{campaign}/resume', [CheckpointCampaignController::class, 'resume'])->name('resume');
        });
        Route::middleware('permission:end checkpoint campaign')->group(function () {
            Route::post('/campaigns/{campaign}/end', [CheckpointCampaignController::class, 'end'])->name('end');
            Route::post('/campaigns/{campaign}/cancel', [CheckpointCampaignController::class, 'cancel'])->name('cancel');
            Route::post('/campaigns/{campaign}/complete', [CheckpointCampaignController::class, 'complete'])->name('complete');
        });
        Route::get('/campaigns/{campaign}/export', [CheckpointCampaignController::class, 'export'])
            ->middleware('permission:export checkpoint reports')->name('export');

        Route::middleware('permission:view checkpoint results')->group(function () {
            Route::get('/results', [CheckpointResultController::class, 'index'])->name('results.index');
            Route::get('/results/{checkpoint}', [CheckpointResultController::class, 'show'])->name('results.show');
        });
        Route::post('/results/{checkpoint}/follow-up', [CheckpointResultController::class, 'followUp'])
            ->middleware('permission:review checkpoint exceptions')->name('results.follow-up');

        Route::middleware('permission:manage checkpoint settings')->group(function () {
            Route::get('/settings', [CheckpointSettingsController::class, 'edit'])->name('settings');
            Route::put('/settings', [CheckpointSettingsController::class, 'update'])->name('settings.update');
        });
    });

    // --- Profile (Breeze) ---
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
