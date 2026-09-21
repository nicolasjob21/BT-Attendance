<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Import Employees</h1>
    </x-slot>
    <x-slot name="back">{{ route('employees.index') }}</x-slot>
    <x-slot name="backLabel">Back to Employees</x-slot>

    <div class="mx-auto max-w-2xl space-y-5">
        <div class="card p-6">
            <p class="text-sm text-gray-600 dark:text-slate-300">Upload an Excel or CSV file (<code>.xlsx</code>, <code>.csv</code>) to onboard many employees at once — each row creates an employee record and a login account.</p>

            <div class="mt-4 rounded-xs bg-gray-50 dark:bg-slate-800/60 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Required columns — first row must be the header</p>
                <div class="mt-2 overflow-x-auto rounded-sm border border-gray-200 dark:border-slate-700">
                    <table class="min-w-full text-xs text-gray-700 dark:text-slate-200">
                        <thead class="bg-white dark:bg-slate-800 font-semibold">
                            <tr>
                                <th class="border-b border-gray-200 dark:border-slate-700 px-3 py-1.5 text-left">first_name</th>
                                <th class="border-b border-gray-200 dark:border-slate-700 px-3 py-1.5 text-left">last_name</th>
                                <th class="border-b border-gray-200 dark:border-slate-700 px-3 py-1.5 text-left">username</th>
                                <th class="border-b border-gray-200 dark:border-slate-700 px-3 py-1.5 text-left">email</th>
                                <th class="border-b border-gray-200 dark:border-slate-700 px-3 py-1.5 text-left">monthly_salary</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="px-3 py-1.5">Juan</td><td class="px-3 py-1.5">Dela Cruz</td><td class="px-3 py-1.5">brite-juan</td><td class="px-3 py-1.5">juan@brite-tsi.com</td><td class="px-3 py-1.5">25000</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5">Maria</td><td class="px-3 py-1.5">Santos</td><td class="px-3 py-1.5 text-gray-400 dark:text-slate-500 italic">(blank → brite-maria)</td><td class="px-3 py-1.5">maria@brite-tsi.com</td><td class="px-3 py-1.5">20000</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-gray-500 dark:text-slate-400">
                    <li>Everyone imported gets the <strong>Employee</strong> role and the office schedule (8:30 AM – 5:30 PM).</li>
                    <li><code>username</code> is the login name (company prefix + first name, e.g. <code>brite-juan</code>). Leave it blank to generate one.</li>
                    <li>Duplicate or invalid emails and usernames are skipped and reported.</li>
                </ul>
            </div>

            <form method="POST" action="{{ route('employees.import.store') }}" enctype="multipart/form-data" class="mt-5 space-y-4">
                @csrf
                <div>
                    <div class="mb-1.5 flex items-center justify-between gap-2">
                        <label for="file" class="block text-sm font-medium text-gray-700 dark:text-slate-200">Excel or CSV file</label>
                        <a href="{{ route('employees.import.template') }}" class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-300">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
                            Download template
                        </a>
                    </div>
                    <input type="file" id="file" name="file" accept=".xlsx,.xls,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required
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

                <div class="form-footer-actions pt-2">
                    <a href="{{ route('employees.index') }}" class="btn-app btn-md btn-secondary">Cancel</a>
                    <button type="submit" class="btn-app btn-md btn-brand">Import</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
