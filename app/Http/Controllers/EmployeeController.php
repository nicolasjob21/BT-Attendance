<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->string('search')->toString();
        $type = $request->string('type')->toString();
        $status = $request->string('status')->toString();

        $employees = Employee::with(['schedule', 'supervisor', 'user'])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%");
                });
            })
            ->when($type, fn ($q) => $q->where('employee_type', $type))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('last_name')
            ->paginate(25)
            ->withQueryString();

        return view('employees.index', compact('employees', 'search', 'type', 'status'));
    }

    public function create()
    {
        return view('employees.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        $tempPassword = ($data['password'] ?? null) ?: Str::password(10);

        DB::transaction(function () use ($data, $tempPassword, &$employee) {
            $user = User::create([
                'name' => trim("{$data['first_name']} {$data['last_name']}"),
                'email' => $data['email'],
                'password' => Hash::make($tempPassword),
                'email_verified_at' => now(),
            ]);
            $user->assignRole($this->safeRole($data['role']));

            $employee = Employee::create($this->employeeAttributes($data, $user->id));
            $employee->update(['employee_no' => $employee->employee_no ?: 'EMP-' . str_pad((string) $employee->id, 4, '0', STR_PAD_LEFT)]);
        });

        return redirect()->route('employees.index')
            ->with('status', "Employee added. Login: {$data['email']} · temporary password: {$tempPassword}");
    }

    public function edit(Employee $employee)
    {
        return view('employees.edit', array_merge($this->formData(), compact('employee')));
    }

    public function update(Request $request, Employee $employee)
    {
        $data = $request->validate($this->rules($employee));

        DB::transaction(function () use ($data, $employee) {
            $employee->update($this->employeeAttributes($data, $employee->user_id));

            if ($user = $employee->user) {
                $user->update([
                    'name' => trim("{$data['first_name']} {$data['last_name']}"),
                    'email' => $data['email'],
                ]);
                if (! empty($data['password'])) {
                    $user->update(['password' => Hash::make($data['password'])]);
                }
                $user->syncRoles([$this->safeRole($data['role'])]);
            }
        });

        return redirect()->route('employees.index')->with('status', 'Employee updated.');
    }

    /** Activate / deactivate without deleting (preserves attendance & payroll history). */
    public function toggleStatus(Employee $employee)
    {
        $employee->update(['status' => $employee->status === 'active' ? 'inactive' : 'active']);

        return back()->with('status', "{$employee->full_name} is now {$employee->status}.");
    }

    // ---- Bulk CSV import ----

    public function importForm()
    {
        return view('employees.import');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
            'default_password' => ['required', 'string', 'min:8'],
        ]);

        $schedules = Schedule::all();
        $adminSched = $schedules->firstWhere('is_flexible', false);
        $flexSched = $schedules->firstWhere('is_flexible', true);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);
        $header = array_map(fn ($h) => Str::slug(trim((string) $h), '_'), $header ?: []);

        $created = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count(array_filter($row)) === 0) {
                continue; // blank line
            }
            $r = array_combine(array_slice($header, 0, count($row)), $row);

            $email = trim($r['email'] ?? '');
            $first = trim($r['first_name'] ?? '');
            $last = trim($r['last_name'] ?? '');
            $type = in_array(($r['employee_type'] ?? ''), ['admin', 'technical'], true) ? $r['employee_type'] : 'admin';
            $salary = is_numeric($r['monthly_salary'] ?? null) ? (float) $r['monthly_salary'] : 0;

            if ($email === '' || $first === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $errors[] = "Row {$rowNum}: missing/invalid name or email.";
                continue;
            }
            if (User::where('email', $email)->exists() || Employee::where('email', $email)->exists()) {
                $skipped++;
                $errors[] = "Row {$rowNum}: {$email} already exists.";
                continue;
            }

            DB::transaction(function () use ($first, $last, $email, $type, $salary, $request, $adminSched, $flexSched) {
                $user = User::create([
                    'name' => trim("{$first} {$last}"),
                    'email' => $email,
                    'password' => Hash::make($request->string('default_password')),
                    'email_verified_at' => now(),
                ]);
                $user->assignRole('employee');

                $emp = Employee::create([
                    'user_id' => $user->id,
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $email,
                    'employee_type' => $type,
                    'schedule_id' => ($type === 'technical' ? $flexSched : $adminSched)?->id,
                    'monthly_salary' => $salary,
                    'daily_rate' => round($salary / 22, 2),
                    'status' => 'active',
                ]);
                $emp->update(['employee_no' => 'EMP-' . str_pad((string) $emp->id, 4, '0', STR_PAD_LEFT)]);
            });
            $created++;
        }
        fclose($handle);

        $msg = "Imported {$created} employees" . ($skipped ? ", skipped {$skipped}." : '.');

        return redirect()->route('employees.index')
            ->with('status', $msg)
            ->with('import_errors', array_slice($errors, 0, 10));
    }

    // ---- helpers ----

    private function rules(?Employee $employee = null): array
    {
        $userId = $employee?->user_id;

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId),
                Rule::unique('employees', 'email')->ignore($employee?->id),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'employee_type' => ['required', 'in:admin,technical'],
            'schedule_id' => ['nullable', 'exists:schedules,id'],
            'supervisor_id' => ['nullable', 'exists:employees,id'],
            'monthly_salary' => ['required', 'numeric', 'min:0'],
            'date_hired' => ['nullable', 'date'],
            'role' => ['required', 'string'],
            'status' => ['required', 'in:active,inactive,on_leave'],
            'password' => [$employee ? 'nullable' : 'nullable', 'string', 'min:8'],
        ];
    }

    private function employeeAttributes(array $data, ?int $userId): array
    {
        return [
            'user_id' => $userId,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'employee_type' => $data['employee_type'],
            'schedule_id' => $data['schedule_id'] ?? null,
            'supervisor_id' => $data['supervisor_id'] ?? null,
            'monthly_salary' => $data['monthly_salary'],
            'daily_rate' => round(((float) $data['monthly_salary']) / 22, 2),
            'date_hired' => $data['date_hired'] ?? null,
            'status' => $data['status'],
        ];
    }

    /** Only the CEO / super admin may assign privileged roles. */
    private function safeRole(string $role): string
    {
        $allowed = auth()->user()->hasRole('superadmin')
            ? ['employee', 'hr', 'superadmin']
            : ['employee'];

        return in_array($role, $allowed, true) ? $role : 'employee';
    }

    private function formData(): array
    {
        return [
            'schedules' => Schedule::orderBy('name')->get(),
            'supervisors' => Employee::where('status', 'active')->orderBy('last_name')->get(),
            'roles' => auth()->user()->hasRole('superadmin')
                ? ['employee' => 'Employee', 'hr' => 'HR', 'superadmin' => 'Super Admin (CEO)']
                : ['employee' => 'Employee'],
        ];
    }
}
