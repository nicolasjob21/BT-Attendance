<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Payroll\PayrollRunner;
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
        $payDay = null;
        if ($canPayroll) {
            $runner = app(PayrollRunner::class);
            $runner->rollForward();
            $payDay = $runner->status();
        }

        // Superadmin is management-only: no clock in/out, leave or OT on the dashboard.
        $canClock = $user->can('clock attendance');
        $onlineNow = $user->can('manage users') ? User::online()->count() : null;

        // Recent activity panels for people who clock in: last punches and
        // the latest leave / OT requests with their status.
        $recentLogs = collect();
        $recentRequests = collect();
        $nextPayDay = null;
        if ($employee && $canClock) {
            $recentLogs = $employee->attendanceLogs()->with('site:id,name')->latest('logged_at')->take(5)->get();
            $leave = $employee->leaveRequests()->with('leaveType:id,name')->latest()->take(5)->get()->map(fn ($r) => [
                'kind' => $r->is_early_leave ? 'Early leave' : ($r->leaveType?->name ?? 'Leave'),
                'when' => $r->date_from->format('M j').($r->date_to && ! $r->date_to->equalTo($r->date_from) ? ' – '.$r->date_to->format('M j') : ''),
                'status' => $r->status, 'at' => $r->created_at, 'href' => route('leave.index'),
            ]);
            $ot = $employee->overtimeRequests()->latest()->take(5)->get()->map(fn ($r) => [
                'kind' => 'Overtime', 'when' => $r->ot_date->format('M j'),
                'status' => $r->status, 'at' => $r->created_at, 'href' => route('overtime.index'),
            ]);
            $recentRequests = $leave->concat($ot)->sortByDesc('at')->take(5)->values();
            $nextPayDay = PayrollPeriod::cutoffFor(Carbon::now())['end'];
        }

        return view('dashboard', compact(
            'employee', 'todayLog', 'isClockedIn', 'myPendingLeave', 'myPendingOt',
            'canApprove', 'canManage', 'canPayroll', 'pendingApprovals',
            'activeEmployees', 'payDay', 'canClock', 'onlineNow',
            'recentLogs', 'recentRequests', 'nextPayDay',
        ));
    }
}
