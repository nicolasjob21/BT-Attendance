<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $employee = $user->employee;

        // --- personal snapshot ---
        $todayLog = null;
        $isClockedIn = false;
        if ($employee) {
            $todayLog = $employee->attendanceLogs()
                ->whereDate('logged_at', Carbon::today())
                ->latest('logged_at')
                ->first();
            $isClockedIn = $todayLog && $todayLog->log_type === 'time_in';
        }

        $myPendingLeave = $employee
            ? $employee->leaveRequests()->where('status', 'pending')->count() : 0;
        $myPendingOt = $employee
            ? $employee->overtimeRequests()->where('status', 'pending')->count() : 0;

        // --- management snapshot ---
        $canApprove = $user->can('approve requests');
        $canManage = $user->can('manage employees');
        $canPayroll = $user->can('run payroll');

        $pendingApprovals = 0;
        if ($canApprove) {
            $pendingApprovals = LeaveRequest::where('status', 'pending')->count()
                + OvertimeRequest::where('status', 'pending')->count();
        }

        $activeEmployees = $canManage ? Employee::where('status', 'active')->count() : null;
        $currentPeriod = $canPayroll ? PayrollPeriod::latest('period_start')->first() : null;

        return view('dashboard', compact(
            'employee', 'todayLog', 'isClockedIn', 'myPendingLeave', 'myPendingOt',
            'canApprove', 'canManage', 'canPayroll', 'pendingApprovals',
            'activeEmployees', 'currentPeriod',
        ));
    }
}
