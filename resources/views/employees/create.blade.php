<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Add Employee</h1>
    </x-slot>
    <x-slot name="back">{{ route('employees.index') }}</x-slot>
    <x-slot name="backLabel">Back to Employees</x-slot>

    <div class="page-form">
        <form method="POST" action="{{ route('employees.store') }}" class="space-y-6 card p-6">
            @csrf
            @include('employees._form')

            <div class="form-footer border-t border-gray-100 dark:border-slate-700 pt-5">
                <p class="text-xs text-gray-500 dark:text-slate-400">This creates the employee record <span class="font-medium">and</span> their login account.</p>
                <div class="form-footer-actions">
                    <a href="{{ route('employees.index') }}" class="btn-app btn-md btn-secondary">Cancel</a>
                    <button type="submit" class="btn-app btn-md btn-brand">Create account</button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
