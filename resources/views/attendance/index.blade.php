<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">My Attendance</h1>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-4">
        <div class="flex justify-end">
            <a href="{{ route('attendance.create') }}" class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-4 py-2 text-sm font-medium text-white hover:from-brand-700 hover:to-accent-600">Clock In / Out</a>
        </div>

        <div class="overflow-hidden card">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Photo</th>
                            <th class="px-4 py-3">Date &amp; time</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Location</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($logs as $log)
                            <tr>
                                <td data-label="Photo" class="px-4 py-3">
                                    @if($log->photo_path)
                                        <img src="{{ Storage::url($log->photo_path) }}" alt="selfie" class="h-10 w-10 rounded-full object-cover">
                                    @else
                                        <span class="grid h-10 w-10 place-items-center rounded-full bg-gray-100 dark:bg-slate-700 text-gray-400 dark:text-slate-500">—</span>
                                    @endif
                                </td>
                                <td data-label="Date &amp; time" class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-medium text-gray-900 dark:text-slate-100">{{ $log->logged_at->format('M j, Y') }}</div>
                                    <div class="text-gray-500 dark:text-slate-400">{{ $log->logged_at->format('g:i A') }}</div>
                                </td>
                                <td data-label="Type" class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $log->log_type === 'time_in' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                                        {{ $log->log_type === 'time_in' ? 'Time In' : 'Time Out' }}
                                    </span>
                                    @if($log->synced_offline)
                                        <span class="ml-1 inline-flex items-center rounded-full bg-sky-100 px-2 py-0.5 text-xs text-sky-700">offline</span>
                                    @endif
                                </td>
                                <td data-label="Location" class="px-4 py-3">
                                    @if($log->latitude && $log->longitude)
                                        <a href="https://www.google.com/maps?q={{ $log->latitude }},{{ $log->longitude }}" target="_blank" rel="noopener"
                                           class="inline-flex items-center gap-1 text-brand-600 hover:underline dark:text-brand-300">
                                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
                                            View on map
                                        </a>
                                        <div class="text-xs text-gray-400 dark:text-slate-500">{{ number_format($log->latitude, 5) }}, {{ number_format($log->longitude, 5) }}</div>
                                    @else
                                        <span class="text-gray-400 dark:text-slate-500">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">No attendance logs yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $logs->links() }}
    </div>
</x-app-layout>
