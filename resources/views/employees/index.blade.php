<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Employees</h1>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-4">

        {{-- Import error report --}}
        @if(session('import_errors') && count(session('import_errors')))
            <div class="rounded-xs border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                <p class="font-medium">Some rows were skipped:</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach(session('import_errors') as $err)<li>{{ $err }}</li>@endforeach
                </ul>
            </div>
        @endif

        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center gap-3">
            <form method="GET" action="{{ route('employees.index') }}" class="flex flex-1 flex-wrap items-center gap-2">
                <input type="text" name="search" value="{{ $search }}" placeholder="Search name, email, or no.…"
                       class="min-w-[200px] flex-1 rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                <select name="type" class="rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">All types</option>
                    <option value="admin" @selected($type === 'admin')>Admin</option>
                    <option value="technical" @selected($type === 'technical')>Technical</option>
                </select>
                <select name="status" class="rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">All statuses</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                    <option value="on_leave" @selected($status === 'on_leave')>On leave</option>
                </select>
                <button class="rounded-xs bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Filter</button>
                @if($search || $type || $status)
                    <a href="{{ route('employees.index') }}" class="text-sm text-gray-500 dark:text-slate-400 hover:underline">Clear</a>
                @endif
            </form>

            <div class="flex gap-2">
                <a href="{{ route('employees.import') }}" class="rounded-xs border border-gray-300 dark:border-slate-600 px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700/60">Import CSV</a>
                <a href="{{ route('employees.create') }}" class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-4 py-2 text-sm font-medium text-white hover:from-brand-700 hover:to-accent-600">+ Add Employee</a>
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-slate-400">{{ $employees->total() }} employee(s)</p>

        <div class="overflow-hidden card">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Employee</th>
                            <th class="px-4 py-3">No.</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Role</th>
                            <th class="px-4 py-3">Schedule</th>
                            <th class="px-4 py-3 text-right">Monthly salary</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($employees as $emp)
                            <tr>
                                <td class="cell-head px-4 py-3">
                                    <div class="font-medium text-gray-900 dark:text-slate-100">{{ $emp->full_name }}</div>
                                    <div class="text-gray-500 dark:text-slate-400">{{ $emp->email }}</div>
                                </td>
                                <td data-label="No." class="px-4 py-3 text-gray-500 dark:text-slate-400">{{ $emp->employee_no }}</td>
                                <td data-label="Type" class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $emp->employee_type === 'technical' ? 'bg-brand-100 text-brand-800' : 'bg-slate-100 text-slate-700' }} capitalize">
                                        {{ $emp->employee_type }}
                                    </span>
                                </td>
                                <td data-label="Role" class="px-4 py-3 capitalize text-gray-600 dark:text-slate-300">{{ $emp->user?->getRoleNames()->first() ?? '—' }}</td>
                                <td data-label="Schedule" class="px-4 py-3 text-gray-700 dark:text-slate-200">{{ $emp->schedule?->name ?? '—' }}</td>
                                <td data-label="Monthly salary" class="px-4 py-3 text-right tabular-nums text-gray-900 dark:text-slate-100">₱{{ number_format($emp->monthly_salary, 2) }}</td>
                                <td data-label="Status" class="px-4 py-3"><x-status-badge :status="$emp->status" /></td>
                                <td data-label="Actions" class="px-4 py-3 text-right">
                                    <a href="{{ route('employees.edit', $emp) }}" class="text-sm font-medium text-brand-700 dark:text-brand-300 hover:underline">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">No employees match your filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $employees->links() }}
    </div>
</x-app-layout>
