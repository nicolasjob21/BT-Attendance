<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Edit Attendance Location</h1>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-4">
        @if($site->attendance_logs_count)
            <div class="rounded-xs border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-900/30 dark:text-amber-200">
                {{ number_format($site->attendance_logs_count) }} attendance punch(es) reference this location. Editing the centre or radius only affects <strong>future</strong> punches — past records keep the distance and verdict computed at the time.
            </div>
        @endif

        <form method="POST" action="{{ route('sites.update', $site) }}" class="space-y-6 card p-6">
            @csrf
            @method('PUT')
            @include('sites._form')

            <div class="flex items-center justify-between border-t border-gray-100 dark:border-slate-700 pt-5">
                <span class="text-xs text-gray-500 dark:text-slate-400">{{ $site->active_assignments_count }} employee(s) currently assigned</span>
                <div class="flex gap-2">
                    <a href="{{ route('sites.index') }}" class="rounded-xs border border-gray-300 dark:border-slate-600 px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700/60">Cancel</a>
                    <button type="submit" class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-4 py-2 text-sm font-semibold text-white hover:from-brand-700 hover:to-accent-600">Save changes</button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
