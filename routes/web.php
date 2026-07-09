<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // --- Self-service (all authenticated staff) ---
    Route::get('/attendance', [AttendanceController::class, 'create'])->name('attendance.create');
    Route::post('/attendance', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::get('/attendance/logs', [AttendanceController::class, 'index'])->name('attendance.index');

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
    Route::get('/overtime/preview', [OvertimeController::class, 'preview'])->name('overtime.preview');
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
    });

    // --- Employee management (HR, Admin) ---
    Route::middleware('permission:manage employees')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('/employees/import', [EmployeeController::class, 'importForm'])->name('employees.import');
        Route::post('/employees/import', [EmployeeController::class, 'import'])->name('employees.import.store');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::patch('/employees/{employee}/status', [EmployeeController::class, 'toggleStatus'])->name('employees.status');
    });

    // --- Payroll (HR, Admin) ---
    Route::middleware('permission:run payroll')->group(function () {
        Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
        Route::post('/payroll/{period}/generate', [PayrollController::class, 'generate'])->name('payroll.generate');
    });

    // --- Profile (Breeze) ---
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
