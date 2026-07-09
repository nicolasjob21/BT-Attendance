<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Edit Employee</h1>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-4">
        <form method="POST" action="{{ route('employees.update', $employee) }}" class="space-y-6 card p-6">
            @csrf
            @method('PUT')
            @include('employees._form')

            <div class="flex items-center justify-between border-t border-gray-100 dark:border-slate-700 pt-5">
                <span class="text-xs text-gray-500 dark:text-slate-400">{{ $employee->employee_no }}</span>
                <div class="flex gap-2">
                    <a href="{{ route('employees.index') }}" class="rounded-xs border border-gray-300 dark:border-slate-600 px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700/60">Cancel</a>
                    <button type="submit" class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-4 py-2 text-sm font-semibold text-white hover:from-brand-700 hover:to-accent-600">Save changes</button>
                </div>
            </div>
        </form>

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
