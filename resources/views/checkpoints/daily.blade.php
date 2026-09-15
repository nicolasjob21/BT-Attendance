@php
    $prev = $date->copy()->subDay()->toDateString();
    $next = $date->copy()->addDay()->toDateString();
    $siteName = $siteId ? $sites->firstWhere('id', $siteId)?->name : null;
@endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Check Point · Daily monitor</h1>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-5">
        {{-- Day / site filter --}}
        <form method="GET" class="card flex flex-col gap-3 p-3 sm:flex-row sm:flex-wrap sm:items-center">
            <div class="flex items-center gap-1">
                <a href="{{ route('checkpoints.daily', ['date' => $prev, 'site' => $siteId]) }}" class="grid h-9 w-9 place-items-center rounded-xs border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700/60" aria-label="Previous day">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()"
                       class="rounded-xs border-gray-300 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                <a href="{{ route('checkpoints.daily', ['date' => $next, 'site' => $siteId]) }}" class="grid h-9 w-9 place-items-center rounded-xs border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700/60" aria-label="Next day">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
                @unless($date->isToday())
                    <a href="{{ route('checkpoints.daily', ['site' => $siteId]) }}" class="ml-1 text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">Today</a>
                @endunless
            </div>
            <select name="site" onchange="this.form.submit()" class="rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                <option value="">All project sites</option>
                @foreach($sites as $s)<option value="{{ $s->id }}" @selected($siteId === $s->id)>{{ $s->name }}</option>@endforeach
            </select>
            <p class="text-sm font-medium text-gray-800 dark:text-slate-100 sm:ml-auto">
                {{ $date->format('l, F j, Y') }}@if($siteName) · {{ $siteName }}@endif
            </p>
        </form>

        {{-- Quick day jumps --}}
        @if($activeDays->isNotEmpty())
            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                <span class="text-gray-500 dark:text-slate-400">Days with checkpoints:</span>
                @foreach($activeDays as $d)
                    <a href="{{ route('checkpoints.daily', ['date' => $d, 'site' => $siteId]) }}"
                       class="rounded-full border px-2.5 py-0.5 font-medium {{ $d === $date->toDateString() ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' : 'border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700/60' }}">
                        {{ \Carbon\Carbon::parse($d)->format('D, M j') }}
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Day totals --}}
        @php
            $tiles = [
                ['Checkpoints', $totals['checkpoints'], 'text-gray-900 dark:text-slate-100'],
                ['Employees', $totals['employees'], 'text-gray-900 dark:text-slate-100'],
                ['Completed', $totals['completed'], 'text-emerald-600 dark:text-emerald-400'],
                ['Non-compliant', $totals['non_compliant'], 'text-rose-600 dark:text-rose-400'],
                ['Open follow-ups', $totals['open_follow_ups'], 'text-amber-600 dark:text-amber-400'],
            ];
        @endphp
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach($tiles as [$label, $value, $tone])
                <div class="card p-4"><p class="eyebrow text-[10px]">{{ $label }}</p><p class="mt-1.5 text-2xl font-bold tabular-nums {{ $tone }}">{{ $value }}</p></div>
            @endforeach
        </div>

        {{-- Checkpoints that day --}}
        <section class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Checkpoints on {{ $date->format('M j') }}</h2>
                <span class="text-xs text-gray-500 dark:text-slate-400">{{ $campaigns->count() }}</span>
            </div>
            <x-checkpoints.campaign-table :campaigns="$campaigns" empty="No checkpoint ran on this day{{ $siteName ? ' at ' . $siteName : '' }}." />
        </section>

        {{-- Employee × checkpoint grid --}}
        @if($campaigns->isNotEmpty())
            <section class="card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Employee responses</h2>
                    <div class="flex flex-wrap gap-3 text-[11px] text-gray-500 dark:text-slate-400">
                        <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-emerald-500"></i>completed</span>
                        <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-rose-500"></i>missed / outside</span>
                        <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-amber-500"></i>needs follow-up / review</span>
                        <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-sky-500"></i>waiting</span>
                        <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-gray-300 dark:bg-slate-600"></i>not included</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-slate-800/60 dark:text-slate-400">
                            <tr>
                                <th class="sticky left-0 z-10 bg-gray-50 px-4 py-3 dark:bg-slate-800/60">Employee</th>
                                @foreach($campaigns as $c)
                                    <th class="px-3 py-2 text-center font-medium normal-case tracking-normal">
                                        <a href="{{ route('checkpoints.show', $c) }}" class="block hover:underline">
                                            <span class="block tabular-nums text-gray-900 dark:text-slate-100">{{ $c->starts_at->format('g:i A') }}</span>
                                            <span class="block max-w-[10rem] truncate text-[11px] text-gray-500 dark:text-slate-400" title="{{ $c->name }}">{{ $c->site?->name }}</span>
                                        </a>
                                    </th>
                                @endforeach
                                <th class="px-3 py-3 text-center">Done</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                            @foreach($grid as $row)
                                @php $emp = $row['employee']; $done = collect($row['cells'])->filter->isCompleted()->count(); $of = count($row['cells']); @endphp
                                <tr class="hover:bg-gray-50/60 dark:hover:bg-slate-800/40">
                                    <td class="sticky left-0 z-10 bg-white px-4 py-2 dark:bg-surface">
                                        <div class="font-medium text-gray-900 dark:text-slate-100">{{ $emp->full_name }}</div>
                                        <div class="text-xs text-gray-500 dark:text-slate-400">{{ $emp->employee_no }}</div>
                                    </td>
                                    @foreach($campaigns as $c)
                                        @php $cp = $row['cells'][$c->id] ?? null; @endphp
                                        <td class="px-3 py-2 text-center">
                                            @if($cp)
                                                @php
                                                    $tone = match (true) {
                                                        $cp->isCompleted() => 'bg-emerald-500',
                                                        in_array($cp->status, [\App\Models\Checkpoint::MISSED, \App\Models\Checkpoint::OUTSIDE_GEOFENCE, \App\Models\Checkpoint::REJECTED_EXCEPTION], true) => 'bg-rose-500',
                                                        in_array($cp->status, [\App\Models\Checkpoint::PENDING, \App\Models\Checkpoint::NOTIFIED], true) && $c->isLive() => 'bg-sky-500',
                                                        default => 'bg-amber-500',
                                                    };
                                                @endphp
                                                <a href="{{ route('checkpoints.results.show', $cp) }}" class="group inline-flex flex-col items-center gap-0.5" title="{{ $cp->status_label }}{{ $cp->submitted_at ? ' · ' . $cp->submitted_at->format('g:i:s A') : '' }}">
                                                    <span class="h-3 w-3 rounded-full {{ $tone }} ring-2 ring-transparent group-hover:ring-brand-300"></span>
                                                    <span class="text-[10px] tabular-nums text-gray-500 group-hover:underline dark:text-slate-400">{{ $cp->submitted_at?->format('g:i') ?? ($cp->isCompleted() ? 'rev.' : '—') }}</span>
                                                </a>
                                            @else
                                                <span class="inline-block h-3 w-3 rounded-full bg-gray-200 dark:bg-slate-700" title="Not included"></span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="px-3 py-2 text-center tabular-nums {{ $done === $of ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">{{ $done }}/{{ $of }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
