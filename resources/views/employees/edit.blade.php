<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Edit Employee</h1>
    </x-slot>
    <x-slot name="back">{{ route('employees.index') }}</x-slot>
    <x-slot name="backLabel">Back to Employees</x-slot>

    <div class="page-form space-y-4">
        <form method="POST" action="{{ route('employees.update', $employee) }}" class="space-y-6 card p-6">
            @csrf
            @method('PUT')
            @include('employees._form')

            <div class="flex items-center justify-between border-t border-gray-100 dark:border-slate-700 pt-5">
                <span class="text-xs text-gray-500 dark:text-slate-400">{{ $employee->employee_no }}</span>
                <div class="flex gap-2">
                    <a href="{{ route('employees.index') }}" class="btn-app btn-md btn-secondary">Cancel</a>
                    <button type="submit" class="btn-app btn-md btn-brand">Save changes</button>
                </div>
            </div>
        </form>

        @include('employees._assignments')

        <form method="POST" action="{{ route('employees.status', $employee) }}"
              onsubmit="return confirm('{{ $employee->status === 'active' ? 'Deactivate' : 'Reactivate' }} this employee?')">
            @csrf
            @method('PATCH')
            <button class="text-sm font-medium {{ $employee->status === 'active' ? 'text-rose-600' : 'text-emerald-600' }} hover:underline">
                {{ $employee->status === 'active' ? 'Deactivate this account' : 'Reactivate this account' }}
            </button>
        </form>
    </div>
</x-app-layout>
