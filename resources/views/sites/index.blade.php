<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Attendance Locations</h1>
    </x-slot>

    <div class="page space-y-4">
        @if(session('status'))
            <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200">
                {{ session('status') }}
            </div>
        @endif

        {{-- Toolbar --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <form method="GET" action="{{ route('sites.index') }}" class="flex flex-wrap items-center gap-2">
                <select name="status" onchange="this.form.submit()"
                        class="rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">All statuses</option>
                    @foreach(\App\Models\Site::STATUSES as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @if($status)
                    <a href="{{ route('sites.index') }}" class="text-sm text-gray-500 dark:text-slate-400 hover:underline">Clear</a>
                @endif
            </form>
            <a href="{{ route('sites.create') }}" class="btn-app btn-md btn-brand">+ Add Location</a>
        </div>

        <p class="text-xs text-gray-500 dark:text-slate-400">
            Employees may clock in at <strong>any active</strong> location — the main office plus every live project site. Finished projects should be marked <em>completed</em>, not deleted, so past attendance keeps its location.
            Geofence mode: <span class="font-medium capitalize text-gray-700 dark:text-slate-200">{{ config('attendance.geofence_mode') }}</span>.
        </p>

        <div class="overflow-hidden card">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                        <tr class="whitespace-nowrap">
                            <th class="px-4 py-3">Location</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Geofence</th>
                            <th class="px-4 py-3">Window</th>
                            <th class="px-4 py-3 text-center">Assigned</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($sites as $site)
                            @php
                                $typeClass = match ($site->type) {
                                    'office' => 'badge-info',
                                    'temporary' => 'badge-warn',
                                    default => 'badge-neutral',
                                };
                                $typeShort = ['office' => 'Main office', 'project_site' => 'Project site', 'temporary' => 'Temporary'][$site->type] ?? $site->type_label;
                                $dimmed = $site->status !== 'active';
                            @endphp
                            <tr class="{{ $dimmed ? 'text-gray-500 dark:text-slate-400' : '' }} hover:bg-gray-50/60 dark:hover:bg-slate-800/40">
                                <td class="cell-head px-4 py-3.5">
                                    <div class="flex items-start gap-3">
                                        <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full {{ $typeClass }}">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                @if($site->isOffice())
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 21V5a2 2 0 012-2h8a2 2 0 012 2v16M4 21h16M16 9h2a2 2 0 012 2v10M8 7h4M8 11h4M8 15h4"/>
                                                @else
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-6-5.2-6-10a6 6 0 1112 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/>
                                                @endif
                                            </svg>
                                        </span>
                                        <div class="min-w-0">
                                            <div class="font-medium {{ $dimmed ? '' : 'text-gray-900 dark:text-slate-100' }}">{{ $site->name }}</div>
                                            <div class="max-w-xs truncate text-xs text-gray-500 dark:text-slate-400" title="{{ $site->address }}">
                                                {{ $site->client_name ? $site->client_name . ' · ' : '' }}{{ $site->address ?: number_format($site->latitude, 5) . ', ' . number_format($site->longitude, 5) }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Type" class="px-4 py-3.5 whitespace-nowrap">
                                    <span class="badge {{ $typeClass }}">{{ $typeShort }}</span>
                                </td>
                                <td data-label="Geofence" class="px-4 py-3.5 whitespace-nowrap tabular-nums">
                                    <div class="row-actions justify-start">
                                        <span class="inline-flex h-8 items-center font-medium {{ $dimmed ? '' : 'text-gray-900 dark:text-slate-100' }}">{{ number_format($site->geofence_radius_m) }} m</span>
                                        <a href="https://www.google.com/maps?q={{ $site->latitude }},{{ $site->longitude }}" target="_blank" rel="noopener" class="is-primary" title="Open in Google Maps">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-6-5.2-6-10a6 6 0 1112 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/></svg>
                                            Map
                                        </a>
                                    </div>
                                </td>
                                <td data-label="Window" class="px-4 py-3.5 whitespace-nowrap text-xs">
                                    @if($site->active_from || $site->active_until)
                                        <span class="{{ $dimmed ? '' : 'text-gray-700 dark:text-slate-200' }}">{{ $site->active_from?->format('M j, Y') ?? '…' }} → {{ $site->active_until?->format('M j, Y') ?? 'open' }}</span>
                                        @if($site->status === 'active' && ! $site->isActiveOn())
                                            <span class="badge badge-danger ml-1">outside window</span>
                                        @endif
                                    @else
                                        <span class="text-gray-400 dark:text-slate-500">Always</span>
                                    @endif
                                </td>
                                <td data-label="Assigned" class="px-4 py-3.5 text-center tabular-nums">
                                    @if($site->isOffice())
                                        <span class="text-xs text-gray-400 dark:text-slate-500" title="The main office needs no assignment — everyone may clock in here">everyone</span>
                                    @else
                                        <span class="font-medium {{ $dimmed ? '' : 'text-gray-900 dark:text-slate-100' }}">{{ $site->active_assignments_count }}</span>
                                        <span class="text-xs text-gray-400 dark:text-slate-500">{{ Str::plural('employee', $site->active_assignments_count) }}</span>
                                    @endif
                                </td>
                                <td data-label="Status" class="px-4 py-3.5 whitespace-nowrap"><x-status-badge :status="$site->status" /></td>
                                <td data-label="Actions" class="px-4 py-3.5 whitespace-nowrap">
                                    <div class="flex justify-end">
                                    <div class="row-actions">
                                        <a href="{{ route('sites.edit', $site) }}" class="is-primary">Edit</a>
                                        @if($site->status === 'active')
                                            @unless($site->isOffice())
                                                <form method="POST" action="{{ route('sites.status', $site) }}" onsubmit="return confirm('Mark {{ addslashes($site->name) }} as completed? Its geofence closes for new punches and active assignments to it are ended.')">
                                                    @csrf @method('PATCH')
                                                    <input type="hidden" name="status" value="completed">
                                                    <button class="is-primary">Complete</button>
                                                </form>
                                            @endunless
                                            <form method="POST" action="{{ route('sites.status', $site) }}" onsubmit="return confirm('Deactivate {{ addslashes($site->name) }}? Nobody will be able to clock in there until it is reactivated.')">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="status" value="inactive">
                                                <button class="is-danger">Deactivate</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('sites.status', $site) }}">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="status" value="active">
                                                <button class="is-success">Reactivate</button>
                                            </form>
                                        @endif
                                    </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">No locations yet. Add the main office first.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
