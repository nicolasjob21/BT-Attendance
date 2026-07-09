<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Add Employee</h1>
    </x-slot>

    <div class="mx-auto max-w-3xl">
        <form method="POST" action="{{ route('employees.store') }}" class="space-y-6 card p-6">
            @csrf
            @include('employees._form')

            <div class="flex items-center justify-between border-t border-gray-100 dark:border-slate-700 pt-5">
                <p class="text-xs text-gray-500 dark:text-slate-400">This creates the employee record <span class="font-medium">and</span> their login account.</p>
                <div class="flex gap-2">
                    <a href="{{ route('employees.index') }}" class="rounded-xs border border-gray-300 dark:border-slate-600 px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700/60">Cancel</a>
                    <button type="submit" class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-4 py-2 text-sm font-semibold text-white hover:from-brand-700 hover:to-accent-600">Create account</button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
