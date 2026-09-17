<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Schedule;
use App\Models\Site;
use App\Models\User;
use App\Services\Payroll\PayrollRates;
use App\Support\RoleMatrix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->string('search')->toString();
        $type = $request->string('type')->toString();
        $status = $request->string('status')->toString();

        $employees = Employee::with(['schedule', 'user', 'activeAssignment.site:id,name'])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$search}%"));
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
        $this->normalizeUsername($request);
        $data = $request->validate($this->rules());

        $tempPassword = ($data['password'] ?? null) ?: Str::password(10);
        $username = ($data['username'] ?? null) ?: User::suggestUsername($data['first_name']);

        DB::transaction(function () use ($data, $username, $tempPassword, &$employee) {
            $user = User::create([
                'name' => trim("{$data['first_name']} {$data['last_name']}"),
                'username' => $username,
                'email' => $data['email'],
                'password' => Hash::make($tempPassword),
                'email_verified_at' => now(),
            ]);
            // Add Employee always creates a staff account. The authorized roles
            // (Super Admin, Admin, Developer) are assigned in User Management.
            $user->assignRole(RoleMatrix::EMPLOYEE);

            $employee = Employee::create($this->employeeAttributes($data, $user->id));
            $employee->update(['employee_no' => $employee->employee_no ?: 'EMP-'.str_pad((string) $employee->id, 4, '0', STR_PAD_LEFT)]);
        });

        return redirect()->route('employees.index')
            ->with('status', "Employee added. Username: {$username} · temporary password: {$tempPassword}");
    }

    public function edit(Employee $employee)
    {
        $employee->load(['projectAssignments.site:id,name,status', 'projectAssignments.creator:id,name']);

        // Only live, non-office sites can be assigned; the office needs no assignment.
        $projectSites = Site::query()->activeOn()->where('type', '!=', 'office')->orderBy('name')->get(['id', 'name', 'type']);
        $activeAssignment = $employee->activeAssignment()->with('site')->first();

        return view('employees.edit', array_merge($this->formData(), compact('employee', 'projectSites', 'activeAssignment')));
    }

    public function update(Request $request, Employee $employee)
    {
        $this->normalizeUsername($request);
        $data = $request->validate($this->rules($employee));

        DB::transaction(function () use ($data, $employee) {
            $employee->update($this->employeeAttributes($data, $employee->user_id));

            if ($user = $employee->user) {
                $user->update([
                    'name' => trim("{$data['first_name']} {$data['last_name']}"),
                    'username' => ($data['username'] ?? null) ?: $user->username,
                    'email' => $data['email'],
                ]);
                if (! empty($data['password'])) {
                    $user->update(['password' => Hash::make($data['password'])]);
                }
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

    // ---- Bulk Excel (.xlsx) import ----

    public function importForm()
    {
        return view('employees.import');
    }

    /** Employee list as Excel (`export employees`). */
    public function export()
    {
        $employees = Employee::with(['schedule', 'user', 'activeAssignment.site:id,name'])->orderBy('last_name')->get();

        $headers = ['employee_no', 'first_name', 'last_name', 'username', 'email', 'phone', 'employee_type', 'role', 'schedule', 'project_site', 'monthly_salary', 'date_hired', 'status'];
        $rows = $employees->map(fn (Employee $e) => [
            $e->employee_no, $e->first_name, $e->last_name, $e->user?->username, $e->email, $e->phone,
            $e->employee_type, $e->user?->getRoleNames()->first(), $e->schedule?->name,
            $e->activeAssignment?->site?->name, (float) $e->monthly_salary, $e->date_hired, $e->status,
        ])->all();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Employees');
        $sheet->fromArray([$headers, ...$rows], null, 'A1');
        $last = chr(ord('A') + count($headers) - 1);
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E7490');
        foreach (range('A', $last) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'employees-'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function importTemplate()
    {
        $headers = ['first_name', 'last_name', 'username', 'email', 'employee_type', 'monthly_salary'];
        $samples = [
            ['Juan', 'Dela Cruz', 'brite-juan', 'juan@brite-tsi.com', 'technical', 25000],
            ['Maria', 'Santos', '', 'maria@brite-tsi.com', 'admin', 20000],
        ];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Employees');
        $sheet->fromArray([$headers, ...$samples], null, 'A1');

        // Style the header row: bold, brand fill, centered.
        $sheet->getStyle('A1:F1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:F1')->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF0E7490'); // brand-700-ish teal
        $sheet->getStyle('A1:F1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2'); // keep header visible while scrolling

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'employee-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
            'default_password' => ['required', 'string', 'min:8'],
        ]);

        $schedules = Schedule::all();
        $adminSched = $schedules->firstWhere('is_flexible', false);
        $flexSched = $schedules->firstWhere('is_flexible', true);

        $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false); // 0-indexed rows/cols, raw (unformatted) values

        $header = array_map(fn ($h) => Str::slug(trim((string) $h), '_'), $rows[0] ?? []);

        $created = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 1;
        $usedUsernames = []; // usernames assigned earlier in this same file

        foreach (array_slice($rows, 1) as $row) {
            $rowNum++;
            $row = array_map(fn ($c) => trim((string) $c), $row);
            if (count(array_filter($row, fn ($c) => $c !== '')) === 0) {
                continue; // blank line
            }
            $r = array_combine(array_slice($header, 0, count($row)), $row);

            $email = trim($r['email'] ?? '');
            $username = User::normalizeUsername($r['username'] ?? '');
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
            if ($username !== '') {
                if (! preg_match(User::USERNAME_PATTERN, $username)) {
                    $skipped++;
                    $errors[] = "Row {$rowNum}: username '{$username}' may only use letters, numbers, - _ . (e.g. brite-juan).";

                    continue;
                }
                if (in_array($username, $usedUsernames, true) || User::where('username', $username)->exists()) {
                    $skipped++;
                    $errors[] = "Row {$rowNum}: username '{$username}' already exists.";

                    continue;
                }
            } else {
                $username = User::suggestUsername($first, $usedUsernames);
            }
            $usedUsernames[] = $username;

            DB::transaction(function () use ($first, $last, $username, $email, $type, $salary, $request, $adminSched, $flexSched) {
                $user = User::create([
                    'name' => trim("{$first} {$last}"),
                    'username' => $username,
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
                    'daily_rate' => round($salary / max(1, PayrollRates::get('working_days_per_month')), 2),
                    'status' => 'active',
                ]);
                $emp->update(['employee_no' => 'EMP-'.str_pad((string) $emp->id, 4, '0', STR_PAD_LEFT)]);
            });
            $created++;
        }

        $msg = "Imported {$created} employees".($skipped ? ", skipped {$skipped}." : '.');

        return redirect()->route('employees.index')
            ->with('status', $msg)
            ->with('import_errors', array_slice($errors, 0, 10));
    }

    // ---- helpers ----

    /** Usernames are stored lowercase; normalise before the unique/regex checks run. */
    private function normalizeUsername(Request $request): void
    {
        $request->merge(['username' => User::normalizeUsername($request->input('username')) ?: null]);
    }

    private function rules(?Employee $employee = null): array
    {
        $userId = $employee?->user_id;

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'username' => [
                'nullable', 'string', 'max:60', 'regex:'.User::USERNAME_PATTERN,
                Rule::unique('users', 'username')->ignore($userId),
            ],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId),
                Rule::unique('employees', 'email')->ignore($employee?->id),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'employee_type' => ['required', 'in:admin,technical'],
            'schedule_id' => ['nullable', 'exists:schedules,id'],
            'monthly_salary' => ['required', 'numeric', 'min:0'],
            'date_hired' => ['nullable', 'date'],
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
            'monthly_salary' => $data['monthly_salary'],
            'daily_rate' => round(((float) $data['monthly_salary']) / max(1, PayrollRates::get('working_days_per_month')), 2),
            'date_hired' => $data['date_hired'] ?? null,
            'status' => $data['status'],
        ];
    }

    private function formData(): array
    {
        return [
            'schedules' => Schedule::orderBy('name')->get(),
        ];
    }
}
