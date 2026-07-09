<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LeaveController extends Controller
{
    public function index(Request $request)
    {
        $employee = $request->user()->employee;
        $canApprove = $request->user()->can('approve requests');

        // Approvers see all requests; everyone else sees only their own.
        $query = LeaveRequest::with(['employee', 'leaveType', 'approver'])->latest();
        if (! $canApprove && $employee) {
            $query->where('employee_id', $employee->id);
        }

        $requests = $query->paginate(20);

        return view('leave.index', compact('requests', 'canApprove'));
    }

    public function create(Request $request)
    {
        $leaveTypes = LeaveType::orderBy('name')->get();

        return view('leave.create', compact('leaveTypes'));
    }

    public function store(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        $data = $request->validate([
            // A half day may be filed without a leave type (reason carries the context);
            // a full-day leave still requires a type.
            'leave_type_id' => ['nullable', 'required_if:day_portion,full', 'exists:leave_types,id'],
            'day_portion' => ['required', 'in:full,half_am,half_pm'],
            'date_from' => ['required', 'date'],
            'date_to' => ['nullable', 'required_if:day_portion,full', 'date', 'after_or_equal:date_from'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'leave_type_id.required_if' => 'Please choose a leave type for a full-day leave.',
            'date_to.required_if' => 'Please choose an end date for a full-day leave.',
        ]);

        // A half day is a single date worth 0.5 days; a full day spans the range.
        if ($data['day_portion'] === 'full') {
            $days = Carbon::parse($data['date_from'])->diffInDays(Carbon::parse($data['date_to'])) + 1;
        } else {
            $data['date_to'] = $data['date_from'];
            $days = 0.5;
        }

        $employee->leaveRequests()->create([
            'leave_type_id' => $data['leave_type_id'] ?? null,
            'day_portion' => $data['day_portion'],
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'days' => $days,
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
        ]);

        return redirect()->route('leave.index')->with('status', 'Leave request submitted for approval.');
    }

    /** Mid-shift "go home early / sick" form (filed while already clocked in). */
    public function earlyCreate(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        $scheduledOut = optional($employee->schedule)->time_out; // e.g. "17:30:00" or null

        return view('leave.early', compact('scheduledOut'));
    }

    /**
     * File an early-leave / sick request for today. It is a half-day (PM) so it
     * reuses the half-day approval flow and half-daily-rate salary impact.
     */
    public function earlyStore(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        $data = $request->validate([
            'requested_time_out' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Please give a reason (e.g. not feeling well).',
            'requested_time_out.required' => 'Please enter the time you need to leave.',
        ]);

        $today = Carbon::today()->toDateString();

        // Auto-tag as Sick Leave so it draws on the right category; harmless if absent.
        $sickTypeId = LeaveType::where('code', 'SL')->value('id');

        $employee->leaveRequests()->create([
            'leave_type_id' => $sickTypeId,
            'day_portion' => 'half_pm',      // leaving early = afternoon off
            'is_early_leave' => true,
            'requested_time_out' => $data['requested_time_out'],
            'date_from' => $today,
            'date_to' => $today,
            'days' => 0.5,
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);

        return redirect()->route('leave.index')
            ->with('status', 'Early-leave request submitted for HR approval.');
    }

    public function approve(Request $request, LeaveRequest $leave)
    {
        $this->decide($request, $leave, 'approved');

        return back()->with('status', 'Leave request approved.');
    }

    public function deny(Request $request, LeaveRequest $leave)
    {
        $this->decide($request, $leave, 'denied');

        return back()->with('status', 'Leave request denied.');
    }

    private function decide(Request $request, LeaveRequest $leave, string $status): void
    {
        $leave->update([
            'status' => $status,
            'approved_by' => $request->user()->employee?->id,
            'approved_at' => now(),
        ]);
    }
}
