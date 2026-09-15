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
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectAssignmentController;
use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // --- Notifications (all authenticated staff) ---
    Route::get('/notifications/{id}', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    // --- Self-service (all authenticated staff) ---
    Route::get('/attendance', [AttendanceController::class, 'create'])->name('attendance.create');
    Route::post('/attendance', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::get('/attendance/logs', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/{id}/softcopy/{type}', [AttendanceController::class, 'softCopy'])
        ->whereIn('type', ['in', 'out'])->name('attendance.softcopy');
    // Monthly timesheet for one employee (own, or anyone's with `view team reports`).
    Route::get('/attendance/timesheet/{employee}', [AttendanceController::class, 'timesheet'])->name('attendance.timesheet');

    // --- Attendance monitor: everyone's time in/out by date (Dept. Head, HR, Admin) ---
    Route::get('/attendance/monitor', [AttendanceController::class, 'monitor'])
        ->middleware('permission:view team reports')->name('attendance.monitor');

    Route::get('/leave', [LeaveController::class, 'index'])->name('leave.index');
    Route::get('/leave/create', [LeaveController::class, 'create'])->name('leave.create');
    Route::post('/leave', [LeaveController::class, 'store'])->name('leave.store');
    Route::get('/leave/early', [LeaveController::class, 'earlyCreate'])->name('leave.early.create');
    Route::post('/leave/early', [LeaveController::class, 'earlyStore'])->name('leave.early.store');

    Route::get('/overtime', [OvertimeController::class, 'index'])->name('overtime.index');
    Route::get('/overtime/create', [OvertimeController::class, 'create'])->name('overtime.create');
    Route::post('/overtime', [OvertimeController::class, 'store'])->name('overtime.store');

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
        Route::post('/employees/import', [EmployeeController::class, 'import'])->name('employees.import.store');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::patch('/employees/{employee}/status', [EmployeeController::class, 'toggleStatus'])->name('employees.status');

        // Project site deployment (which site an employee is assigned to).
        Route::post('/employees/{employee}/assignments', [ProjectAssignmentController::class, 'store'])->name('employees.assignments.store');
        Route::patch('/employees/{employee}/assignments/{assignment}/end', [ProjectAssignmentController::class, 'end'])->name('employees.assignments.end');
    });

    // --- Settings: attendance locations / geofences (HR, Admin) ---
    Route::middleware('permission:manage settings')->group(function () {
        Route::get('/settings/sites', [SiteController::class, 'index'])->name('sites.index');
        Route::get('/settings/sites/create', [SiteController::class, 'create'])->name('sites.create');
        Route::post('/settings/sites', [SiteController::class, 'store'])->name('sites.store');
        Route::get('/settings/sites/{site}/edit', [SiteController::class, 'edit'])->name('sites.edit');
        Route::put('/settings/sites/{site}', [SiteController::class, 'update'])->name('sites.update');
        Route::patch('/settings/sites/{site}/status', [SiteController::class, 'setStatus'])->name('sites.status');
    });

    // --- Payroll (HR, Admin) ---
    Route::middleware('permission:run payroll')->group(function () {
        Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
        Route::post('/payroll/{period}/generate', [PayrollController::class, 'generate'])->name('payroll.generate');
        Route::get('/employees/{employee}/salary-history', [PayrollController::class, 'salaryHistory'])->name('employees.salary-history');
    });

    // --- Check Point: employee side (respond to an open checkpoint) ---
    Route::get('/my-checkpoints', [EmployeeCheckpointController::class, 'index'])->name('my-checkpoints.index');
    Route::get('/my-checkpoints/{checkpoint}', [EmployeeCheckpointController::class, 'show'])->name('my-checkpoints.show');
    Route::post('/my-checkpoints/{checkpoint}', [EmployeeCheckpointController::class, 'submit'])->name('my-checkpoints.submit');
    Route::post('/my-checkpoints/{checkpoint}/explain', [EmployeeCheckpointController::class, 'explain'])->name('my-checkpoints.explain');
    // Private checkpoint photo: owner or anyone with `view checkpoint results` (checked in the controller).
    Route::get('/checkpoint-photos/{checkpoint}', [CheckpointResultController::class, 'photo'])->name('checkpoints.photo');

    // --- Check Point: management (HR, Admin) — every action is permission-gated server-side ---
    Route::prefix('checkpoints')->name('checkpoints.')->middleware('permission:view checkpoint module')->group(function () {
        Route::get('/', [CheckpointCampaignController::class, 'index'])->name('index');
        Route::get('/history', [CheckpointCampaignController::class, 'history'])->name('history');

        Route::middleware('permission:create checkpoint campaign')->group(function () {
            Route::get('/create', [CheckpointCampaignController::class, 'create'])->name('create');
            Route::post('/', [CheckpointCampaignController::class, 'store'])->name('store');
            Route::get('/campaigns/{campaign}/edit', [CheckpointCampaignController::class, 'edit'])->name('edit');
            Route::put('/campaigns/{campaign}', [CheckpointCampaignController::class, 'update'])->name('update');
        });

        Route::get('/campaigns/{campaign}', [CheckpointCampaignController::class, 'show'])->name('show');
        Route::post('/campaigns/{campaign}/activate', [CheckpointCampaignController::class, 'activate'])
            ->middleware('permission:activate checkpoint campaign')->name('activate');
        Route::middleware('permission:pause checkpoint campaign')->group(function () {
            Route::post('/campaigns/{campaign}/pause', [CheckpointCampaignController::class, 'pause'])->name('pause');
            Route::post('/campaigns/{campaign}/resume', [CheckpointCampaignController::class, 'resume'])->name('resume');
        });
        Route::middleware('permission:end checkpoint campaign')->group(function () {
            Route::post('/campaigns/{campaign}/end', [CheckpointCampaignController::class, 'end'])->name('end');
            Route::post('/campaigns/{campaign}/cancel', [CheckpointCampaignController::class, 'cancel'])->name('cancel');
            Route::post('/campaigns/{campaign}/close', [CheckpointCampaignController::class, 'close'])->name('close');
        });
        Route::get('/campaigns/{campaign}/export', [CheckpointCampaignController::class, 'export'])
            ->middleware('permission:export checkpoint reports')->name('export');

        Route::middleware('permission:view checkpoint results')->group(function () {
            Route::get('/results', [CheckpointResultController::class, 'index'])->name('results.index');
            Route::get('/results/{checkpoint}', [CheckpointResultController::class, 'show'])->name('results.show');
        });
        Route::middleware('permission:review checkpoint exceptions')->group(function () {
            Route::post('/results/{checkpoint}/review', [CheckpointResultController::class, 'review'])->name('results.review');
            Route::post('/results/{checkpoint}/remarks', [CheckpointResultController::class, 'remarks'])->name('results.remarks');
        });

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
