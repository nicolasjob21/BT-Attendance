<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeProjectAssignment;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Deploy an employee to a project site, or pull them off one. */
class ProjectAssignmentController extends Controller
{
    public function store(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'site_id' => [
                'required',
                Rule::exists('sites', 'id')->where(fn ($q) => $q->where('status', 'active')->where('type', '!=', 'office')),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'assignment_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $site = Site::findOrFail($data['site_id']);
        $start = Carbon::parse($data['start_date']);

        DB::transaction(function () use ($employee, $data, $start, $request) {
            // Only one assignment is in force at a time: close the current one
            // the day before the new deployment starts.
            $employee->projectAssignments()->activeOn($start)->get()
                ->each(fn (EmployeeProjectAssignment $a) => $a->end($start->copy()->subDay()));

            $employee->projectAssignments()->create([
                'site_id' => $data['site_id'],
                'start_date' => $start->toDateString(),
                'end_date' => $data['end_date'] ?? null,
                'status' => 'active',
                'assignment_notes' => $data['assignment_notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
        });

        return back()->with('status', "{$employee->full_name} assigned to {$site->name} from {$start->format('M j, Y')}.");
    }

    /** End (or cancel) an assignment; the employee reverts to office/unassigned. */
    public function end(Request $request, Employee $employee, EmployeeProjectAssignment $assignment)
    {
        abort_unless($assignment->employee_id === $employee->id, 404);

        $data = $request->validate([
            'status' => ['nullable', 'in:ended,cancelled'],
            'end_date' => ['nullable', 'date'],
        ]);

        $assignment->end(
            isset($data['end_date']) ? Carbon::parse($data['end_date']) : null,
            $data['status'] ?? 'ended',
        );

        return back()->with('status', "Assignment to {$assignment->site->name} {$assignment->status}.");
    }
}
