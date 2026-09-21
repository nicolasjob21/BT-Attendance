<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Dashboard</h1>
    </x-slot>

    <div class="page space-y-6">

        {{-- Greeting hero --}}
        <div class="hero-gradient relative overflow-hidden rounded-xs p-6 shadow-xs sm:p-8">
            <div class="absolute -right-8 -top-10 h-40 w-40 rounded-full bg-brand-300/30 dark:bg-white/8"></div>
            <div class="absolute -bottom-14 right-24 h-40 w-40 rounded-full bg-accent-300/35 dark:bg-accent-500/15"></div>
            <div class="relative flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="hero-muted text-sm font-medium"
                       x-data="{ now: new Date() }"
                       x-init="setInterval(() => now = new Date(), 1000)">
                        <span x-text="now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: 'Asia/Manila' })">{{ now()->format('l, F j, Y') }}</span>
                        <span class="mx-1 hidden opacity-60 sm:inline">·</span>
                        <span class="hero-strong block tabular-nums text-lg font-bold sm:inline sm:text-xl" x-text="now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true, timeZone: 'Asia/Manila' })">{{ now()->format('g:i:s A') }}</span>
                    </p>
                    <h2 class="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">
                        Welcome back, {{ Str::of(auth()->user()->name)->explode(' ')->first() }}
                    </h2>
                    <p class="hero-muted mt-1 text-sm">
                        @if($employee)
                            {{ $employee->employee_no }}
                            @if($employee->schedule) · {{ $employee->schedule->name }} @endif
                        @endif
                    </p>
                </div>
                @if($canClock)
                <a href="{{ route('attendance.create') }}"
                   class="btn-app btn-md hero-btn">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v4l2.5 2"/></svg>
                    {{ $isClockedIn ? 'Clock out' : 'Clock in now' }}
                </a>
                @endif
            </div>
        </div>

        @php
            $icons = [
                'clock'    => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v4l2.5 2"/>',
                'calendar' => '<rect x="4" y="5" width="16" height="16" rx="2"/><path stroke-linecap="round" d="M4 9h16M8 3v4M16 3v4"/>',
                'plus'     => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 9v6M9 12h6"/>',
                'check'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
                'list'     => '<path stroke-linecap="round" d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
                'users'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-4-4h-1m-4 5H2v-1a4 4 0 014-4h4a4 4 0 014 4v1zm-3-11a3 3 0 11-6 0 3 3 0 016 0zm7 1a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/>',
                'cash'     => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
                'shield'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/>',
                'pin'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-6-5.2-6-10a6 6 0 1112 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/>',
                'usercog'  => '<circle cx="10" cy="8" r="3.5"/><path stroke-linecap="round" d="M3 20v-1a5 5 0 015-5h3"/><circle cx="17.5" cy="16.5" r="2.5"/><path stroke-linecap="round" d="M17.5 12.5v1.5M17.5 19v1.5M13.5 16.5H15M20 16.5h1.5"/>',
                'wifi'     => '<path stroke-linecap="round" d="M5 12.5a10 10 0 0114 0M8.5 16a5 5 0 017 0"/><circle cx="12" cy="19.5" r="1"/>',
            ];
            $chip = [
                'brand'  => 'bg-brand-100 text-brand-600 dark:bg-brand-900/40 dark:text-brand-300',
                'accent' => 'bg-accent-100 text-accent-600 dark:bg-accent-900/30 dark:text-accent-300',
                'violet' => 'bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300',
                'amber'  => 'bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300',
                'emerald'=> 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300',
                'slate'  => 'bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300',
                'rose'   => 'bg-rose-100 text-rose-600 dark:bg-rose-900/40 dark:text-rose-300',
            ];

            // Stat tiles: what this role cares about, in order. Always four so the row stays composed.
            $tiles = [];
            if ($canClock) {
                $tiles[] = ['icon' => 'clock', 'tone' => $isClockedIn ? 'emerald' : 'brand', 'label' => 'Today',
                    'value' => $isClockedIn ? 'Clocked in' : ($todayLog ? 'Clocked out' : 'Not clocked in'),
                    'valueClass' => $isClockedIn ? 'text-emerald-600 dark:text-emerald-400' : ($todayLog ? '' : 'text-gray-400 dark:text-slate-500'),
                    'sub' => $isClockedIn ? 'since '.$todayLog->logged_at->format('g:i A') : ($todayLog ? 'at '.$todayLog->logged_at->format('g:i A') : 'No punch yet today'),
                    'href' => route('attendance.create'), 'link' => $isClockedIn ? 'Clock out' : 'Clock in'];
                $tiles[] = ['icon' => 'calendar', 'tone' => 'accent', 'label' => 'My pending leave', 'value' => $myPendingLeave, 'href' => route('leave.index'), 'link' => 'View leave'];
                $tiles[] = ['icon' => 'plus', 'tone' => 'violet', 'label' => 'My pending OT', 'value' => $myPendingOt, 'href' => route('overtime.index'), 'link' => 'View overtime'];
            }
            if ($canApprove) {
                $tiles[] = ['icon' => 'check', 'tone' => $pendingApprovals ? 'amber' : 'slate', 'label' => 'Awaiting your approval', 'value' => $pendingApprovals,
                    'valueClass' => $pendingApprovals ? 'text-amber-600 dark:text-amber-300' : '', 'href' => route('leave.index'), 'link' => 'Review requests'];
            }
            if ($canManage && $activeEmployees !== null) {
                $tiles[] = ['icon' => 'users', 'tone' => 'brand', 'label' => 'Active employees', 'value' => $activeEmployees, 'href' => route('employees.index'), 'link' => 'Manage employees'];
            }
            if ($onlineNow !== null) {
                $tiles[] = ['icon' => 'wifi', 'tone' => 'emerald', 'label' => 'Online now', 'value' => $onlineNow, 'valueClass' => 'text-emerald-600 dark:text-emerald-400', 'href' => route('users.index', ['status' => 'online']), 'link' => 'User Management'];
            }
            if ($canPayroll && $payDay) {
                // What payroll needs right now: due today / overdue / ready to release / next pay day.
                $payTone = ['overdue' => 'rose', 'due' => 'amber', 'computed' => 'brand', 'upcoming' => 'accent'][$payDay['state']];
                $tiles[] = ['icon' => 'cash', 'tone' => $payTone, 'label' => 'Payroll', 'small' => true,
                    'value' => $payDay['state'] === 'upcoming' ? 'Next pay day '.$payDay['pay_date']->format('M j') : $payDay['title'],
                    'valueClass' => ['overdue' => 'text-rose-600 dark:text-rose-400', 'due' => 'text-amber-600 dark:text-amber-300', 'computed' => 'text-brand-700 dark:text-brand-300', 'upcoming' => ''][$payDay['state']],
                    'sub' => $payDay['state'] === 'upcoming' ? ($payDay['days'] === 0 ? 'Today' : 'in '.$payDay['days'].' day'.($payDay['days'] === 1 ? '' : 's')).' · '.$payDay['pay_date']->format('l') : \Illuminate\Support\Str::limit($payDay['message'], 60),
                    'href' => $payDay['period'] ? route('payroll.index', ['period' => $payDay['period']->id]) : route('payroll.index'),
                    'link' => ['overdue' => 'Run payroll', 'due' => 'Run payroll', 'computed' => 'Review & release', 'upcoming' => 'Open payroll'][$payDay['state']]];
            }
            if (! $canClock && ! $canApprove) {
                $tiles[] = ['icon' => 'list', 'tone' => 'slate', 'label' => 'My attendance', 'value' => '—', 'href' => route('attendance.index'), 'link' => 'View history'];
            }
            // Employees only have three personal tiles — the fourth is their next pay day.
            if (count($tiles) < 4 && $nextPayDay) {
                $tiles[] = ['icon' => 'cash', 'tone' => 'emerald', 'label' => 'Next pay day', 'value' => $nextPayDay->format('M j'),
                    'sub' => $nextPayDay->format('l').' · '.($nextPayDay->day === 15 ? '1st–15th' : '16th–end').' cutoff', 'href' => route('payroll.mine'), 'link' => 'My payslips'];
            }
            $tiles = array_slice($tiles, 0, 4);

            // Quick actions, by permission.
            $actions = [];
            if ($canClock) {
                $actions[] = ['icon' => 'clock', 'tone' => 'brand', 'label' => 'Clock in / out', 'hint' => 'Selfie + GPS punch', 'href' => route('attendance.create')];
            }
            if (auth()->user()->can('request leave')) {
                $actions[] = ['icon' => 'calendar', 'tone' => 'accent', 'label' => 'File leave', 'hint' => 'Vacation, sick, early leave', 'href' => route('leave.create')];
            }
            if (auth()->user()->can('request overtime')) {
                $actions[] = ['icon' => 'plus', 'tone' => 'violet', 'label' => 'Request overtime', 'hint' => 'Pre-approval for extra hours', 'href' => route('overtime.create')];
            }
            if ($canApprove) {
                $actions[] = ['icon' => 'check', 'tone' => 'amber', 'label' => 'Review requests', 'hint' => $pendingApprovals.' pending', 'href' => route('leave.index')];
            }
            if (auth()->user()->can('view team reports')) {
                $actions[] = ['icon' => 'list', 'tone' => 'slate', 'label' => 'Attendance log', 'hint' => "Everyone's time in / out", 'href' => route('attendance.monitor')];
            }
            if ($canManage) {
                $actions[] = ['icon' => 'users', 'tone' => 'brand', 'label' => 'Add employee', 'hint' => 'Creates the login account too', 'href' => route('employees.create')];
            }
            if (auth()->user()->can('view checkpoint module')) {
                $actions[] = ['icon' => 'shield', 'tone' => 'emerald', 'label' => 'Check Point', 'hint' => 'Random presence checks', 'href' => route('checkpoints.index')];
            }
            if (auth()->user()->canAny(['manage settings', 'manage sites'])) {
                $actions[] = ['icon' => 'pin', 'tone' => 'accent', 'label' => 'Locations', 'hint' => 'Sites and geofences', 'href' => route('sites.index')];
            }
            if (auth()->user()->can('manage users')) {
                $actions[] = ['icon' => 'usercog', 'tone' => 'violet', 'label' => 'User Management', 'hint' => 'Accounts, roles, presence', 'href' => route('users.index')];
            }
        @endphp

        {{-- Stat tiles --}}
        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            @foreach($tiles as $t)
                <div class="card card-hover flex min-w-0 flex-col p-4 sm:p-5">
                    <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:gap-3">
                        <span class="icon-chip {{ $chip[$t['tone']] }}">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">{!! $icons[$t['icon']] !!}</svg>
                        </span>
                        <span class="eyebrow">{{ $t['label'] }}</span>
                    </div>
                    <p class="mt-3 break-words font-bold tabular-nums text-gray-900 dark:text-slate-100 sm:mt-4 {{ ($t['small'] ?? false) ? 'text-base sm:text-lg' : 'text-2xl sm:text-3xl' }} {{ $t['valueClass'] ?? '' }}">{{ $t['value'] }}</p>
                    @if(!empty($t['badge']))
                        <div class="mt-1"><x-status-badge :status="$t['badge']" /></div>
                    @elseif(!empty($t['sub']))
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">{{ $t['sub'] }}</p>
                    @endif
                    <a href="{{ $t['href'] }}" class="mt-auto inline-flex items-center gap-1 border-t border-gray-100 pt-3 text-sm font-medium text-brand-700 hover:underline dark:border-slate-700/70 dark:text-brand-300">
                        {{ $t['link'] }} <span aria-hidden="true">&rarr;</span>
                    </a>
                </div>
            @endforeach
        </div>

        {{-- Quick actions --}}
        <div class="card p-5 sm:p-6">
            <div class="mb-4 flex items-end justify-between gap-3">
                <div>
                    <p class="eyebrow">Quick actions</p>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Shortcuts for what you do most.</p>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($actions as $a)
                    <a href="{{ $a['href'] }}"
                       class="group flex items-center gap-3 rounded-xs border border-gray-200 bg-white p-3.5 transition hover:border-brand-400 hover:bg-brand-50/60 dark:border-slate-700 dark:bg-transparent dark:hover:border-brand-500/60 dark:hover:bg-brand-500/10">
                        <span class="icon-chip {{ $chip[$a['tone']] }}">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">{!! $icons[$a['icon']] !!}</svg>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-gray-900 dark:text-slate-100">{{ $a['label'] }}</span>
                            <span class="block truncate text-xs text-gray-500 dark:text-slate-400">{{ $a['hint'] }}</span>
                        </span>
                        <svg class="ml-auto h-4 w-4 shrink-0 text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600 dark:text-slate-600 dark:group-hover:text-brand-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Recent activity: last punches and latest requests --}}
        @if($canClock && $employee)
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="card overflow-hidden">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-slate-700">
                        <p class="eyebrow">Recent attendance</p>
                        <a href="{{ route('attendance.index') }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">All attendance →</a>
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($recentLogs as $log)
                            <li class="flex items-center gap-3 px-5 py-2.5 text-sm">
                                <span class="badge {{ $log->log_type === 'time_in' ? 'badge-success' : 'badge-neutral' }} w-20 justify-center">{{ $log->log_type === 'time_in' ? 'Time in' : 'Time out' }}</span>
                                <span class="min-w-0 flex-1 text-gray-700 dark:text-slate-200"><span class="whitespace-nowrap">{{ $log->logged_at->format('D, M j') }}</span> <span class="hidden text-gray-400 sm:inline dark:text-slate-500">·</span> <span class="block truncate text-xs text-gray-500 sm:inline sm:text-sm sm:text-gray-700 dark:text-slate-400 sm:dark:text-slate-200">{{ $log->site?->name ?? 'Location not verified' }}</span></span>
                                <span class="tabular-nums font-medium text-gray-900 dark:text-slate-100">{{ $log->logged_at->format('g:i A') }}</span>
                            </li>
                        @empty
                            <li><x-empty-state icon="clock" title="No punches yet" hint="Your clock ins and outs will show here." class="py-8" /></li>
                        @endforelse
                    </ul>
                </div>

                <div class="card overflow-hidden">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-slate-700">
                        <p class="eyebrow">My requests</p>
                        <a href="{{ route('leave.index') }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">Leave &amp; overtime →</a>
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($recentRequests as $r)
                            @php $tone = ['approved' => 'badge-success', 'denied' => 'badge-danger', 'pending' => 'badge-warn'][$r['status']] ?? 'badge-neutral'; @endphp
                            <li class="flex items-center gap-3 px-5 py-2.5 text-sm">
                                <a href="{{ $r['href'] }}" class="min-w-0 flex-1 truncate">
                                    <span class="font-medium text-gray-900 dark:text-slate-100">{{ $r['kind'] }}</span>
                                    <span class="text-gray-500 dark:text-slate-400">· {{ $r['when'] }}</span>
                                </a>
                                <span class="text-xs text-gray-400 dark:text-slate-500">{{ $r['at']->diffForHumans(null, true) }} ago</span>
                                <span class="badge {{ $tone }} capitalize">{{ $r['status'] }}</span>
                            </li>
                        @empty
                            <li><x-empty-state icon="calendar" title="No requests yet" hint="Leave and overtime you file will show here with their status." class="py-8" /></li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
