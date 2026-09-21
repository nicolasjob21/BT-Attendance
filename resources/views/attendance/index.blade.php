<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">My Attendance</h1>
    </x-slot>

    <div class="page space-y-4">
        {{-- Immediate download prompt right after a successful Time In / Time Out --}}
        @if(session('softcopy_log_id'))
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-900/50 dark:bg-emerald-900/30">
                <p class="text-sm text-emerald-800 dark:text-emerald-200">Your proof-of-attendance soft copy is ready — keep it for your records.</p>
                <a href="{{ route('attendance.softcopy', ['id' => session('softcopy_log_id'), 'type' => session('softcopy_type')]) }}"
                   class="btn-app btn-md btn-success">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
                    Download Soft Copy
                </a>
            </div>
        @endif

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-gray-500 dark:text-slate-400">Every clock in and out you have recorded, newest first. {{ now()->format('F') }} so far:</p>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('attendance.timesheet', auth()->user()->employee) }}" class="btn-app btn-md btn-secondary">Monthly timesheet</a>
                <a href="{{ route('attendance.create') }}" class="btn-app btn-md btn-brand">Clock In / Out</a>
            </div>
        </div>

        <div class="stat-strip">
            <x-stat label="Days worked" :value="$month['days']" :hint="now()->format('F Y')" tone="brand" />
            <x-stat label="Hours worked" :value="sprintf('%d:%02d', intdiv($month['minutes'], 60), $month['minutes'] % 60)" hint="closed sessions only" />
            <x-stat label="Late arrivals" :value="$month['late']" :hint="$month['late'] ? 'after the 15-minute grace' : 'none — nice'" :tone="$month['late'] ? 'warn' : 'success'" />
            <x-stat label="Last punch" :value="$month['last']?->logged_at->format('g:i A') ?? '—'" :hint="$month['last'] ? ($month['last']->log_type === 'time_in' ? 'Time in · ' : 'Time out · ').$month['last']->logged_at->format('D, M j') : 'No punches yet'" :tone="$month['last'] ? 'neutral' : 'muted'" />
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
                            <th class="px-4 py-3">Soft copy</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($logs as $log)
                            <tr>
                                <td data-label="Photo" class="px-4 py-3">
                                    @if($log->photo_path)
                                        <img src="{{ Storage::url($log->photo_path) }}" alt="selfie"
                                             @click="$dispatch('open-lightbox', '{{ Storage::url($log->photo_path) }}')"
                                             class="h-10 w-10 cursor-zoom-in rounded-full object-cover transition hover:opacity-80 hover:ring-2 hover:ring-brand-500">
                                    @else
                                        <span class="grid h-10 w-10 place-items-center rounded-full bg-gray-100 dark:bg-slate-700 text-gray-400 dark:text-slate-500">—</span>
                                    @endif
                                </td>
                                <td data-label="Date &amp; time" class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-medium text-gray-900 dark:text-slate-100">{{ $log->logged_at->format('M j, Y') }}</div>
                                    <div class="text-gray-500 dark:text-slate-400">{{ $log->logged_at->format('g:i A') }}</div>
                                </td>
                                <td data-label="Type" class="px-4 py-3">
                                    <span class="badge {{ $log->log_type === 'time_in' ? 'badge-success' : 'badge-neutral' }}">
                                        {{ $log->log_type === 'time_in' ? 'Time In' : 'Time Out' }}
                                    </span>
                                    @if($log->synced_offline)
                                        <span class="badge badge-info ml-1">offline</span>
                                    @endif
                                </td>
                                <td data-label="Location" class="px-4 py-3">
                                    <div class="flex flex-col items-end gap-1 sm:items-start">
                                        @if($log->site)
                                            <span class="font-medium text-gray-900 dark:text-slate-100">{{ $log->site->name }}</span>
                                        @elseif($log->location_status)
                                            <span class="text-gray-500 dark:text-slate-400">No site matched</span>
                                        @endif
                                        @if($log->location_status)
                                            <x-location-badge :status="$log->location_status" :verification="$log->location_verification_status" compact />
                                        @endif
                                        @if($log->latitude && $log->longitude)
                                            <a href="https://www.google.com/maps?q={{ $log->latitude }},{{ $log->longitude }}" target="_blank" rel="noopener"
                                               class="inline-flex items-center gap-1 text-xs text-brand-600 hover:underline dark:text-brand-300">
                                                <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
                                                View on map
                                                @if($log->distance_m !== null && ! $log->within_geofence)
                                                    <span class="text-gray-400 dark:text-slate-500">({{ number_format($log->distance_m) }} m away)</span>
                                                @endif
                                            </a>
                                        @elseif(! $log->location_status)
                                            <span class="text-gray-400 dark:text-slate-500">—</span>
                                        @endif
                                        @if($log->location_remarks)
                                            <p class="max-w-xs text-right text-xs text-gray-500 dark:text-slate-400 sm:text-left">HR: “{{ $log->location_remarks }}”@if($log->locationVerifier) — {{ $log->locationVerifier->name }}@endif</p>
                                        @endif
                                    </div>
                                </td>
                                <td data-label="Soft copy" class="px-4 py-3">
                                    <a href="{{ route('attendance.softcopy', ['id' => $log->id, 'type' => $log->log_type === 'time_in' ? 'in' : 'out']) }}"
                                       class="inline-flex items-center gap-1 text-brand-600 hover:underline dark:text-brand-300">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
                                        Download
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-0"><x-empty-state icon="clock" title="No attendance yet" hint="Your first clock in will appear here with its selfie, time and location." :href="route('attendance.create')" action="Clock in now" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $logs->links() }}
    </div>
</x-app-layout>
