<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Import Employees</h1>
    </x-slot>

    <div class="mx-auto max-w-2xl space-y-5">
        <div class="card p-6">
            <p class="text-sm text-gray-600 dark:text-slate-300">Upload a CSV to onboard many employees at once — each row creates an employee record and a login account.</p>

            <div class="mt-4 rounded-xs bg-gray-50 dark:bg-slate-800/60 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Required columns (header row)</p>
                <code class="mt-2 block overflow-x-auto whitespace-pre rounded-sm bg-white dark:bg-slate-800 px-3 py-2 text-xs text-gray-700 dark:text-slate-200 border border-gray-200 dark:border-slate-700">first_name,last_name,email,employee_type,monthly_salary
Juan,Dela Cruz,juan@brite-tsi.com,technical,25000
Maria,Santos,maria@brite-tsi.com,admin,20000</code>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-gray-500 dark:text-slate-400">
                    <li><code>employee_type</code> must be <code>admin</code> or <code>technical</code> (defaults to admin).</li>
                    <li>Everyone imported gets the <strong>Employee</strong> role and the matching default schedule.</li>
                    <li>Duplicate or invalid emails are skipped and reported.</li>
                </ul>
            </div>

            <form method="POST" action="{{ route('employees.import.store') }}" enctype="multipart/form-data" class="mt-5 space-y-4">
                @csrf
                <div>
                    <label for="file" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">CSV file</label>
                    <input type="file" id="file" name="file" accept=".csv,text/csv" required
                           class="block w-full text-sm text-gray-600 dark:text-slate-300 file:mr-3 file:rounded-xs file:border-0 file:bg-brand-600 file:px-4 file:py-2 file:text-white hover:file:bg-brand-700">
                    @error('file') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="default_password" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Initial password for all imported accounts</label>
                    <input type="text" id="default_password" name="default_password" value="{{ old('default_password', 'Brite@2026') }}" required
                           class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Share this with staff; they can change it later in Profile.</p>
                    @error('default_password') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <a href="{{ route('employees.index') }}" class="rounded-xs border border-gray-300 dark:border-slate-600 px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700/60">Cancel</a>
                    <button type="submit" class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-4 py-2 text-sm font-semibold text-white hover:from-brand-700 hover:to-accent-600">Import</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
