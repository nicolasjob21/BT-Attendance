<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Check Point · Settings</h1>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-4">
        <a href="{{ route('checkpoints.index') }}" class="text-sm text-brand-700 hover:underline dark:text-brand-300">← Back to Check Point</a>
        @if(session('status'))
            <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('checkpoints.settings.update') }}" class="card space-y-5 p-5">
            @csrf @method('PUT')
            <div>
                <label for="response_window_minutes" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Default response window (minutes)</label>
                <input type="number" id="response_window_minutes" name="response_window_minutes" min="3" max="120" value="{{ old('response_window_minutes', $defaults['response_window_minutes']) }}" required
                       class="w-full rounded-xs border-gray-300 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600 sm:max-w-xs">
                @error('response_window_minutes') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="instructions" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Instruction library</label>
                <p class="mb-2 text-xs text-gray-500 dark:text-slate-400">One instruction per line. Offered as suggestions when creating a checkpoint.</p>
                <textarea id="instructions" name="instructions" rows="8" required class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">{{ old('instructions', implode("\n", $instructions)) }}</textarea>
                @error('instructions') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div class="rounded-xs border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-600 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-300">
                GPS accuracy threshold follows the attendance setting (<strong>{{ config('attendance.min_gps_accuracy_m') }} m</strong>). Deadlines are enforced by the server; run <code>php artisan schedule:work</code> so scheduled starts and expirations fire on time.
            </div>
            <div class="flex justify-end">
                <button class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-5 py-2 text-sm font-semibold text-white hover:from-brand-700 hover:to-accent-600">Save settings</button>
            </div>
        </form>
    </div>
</x-app-layout>
