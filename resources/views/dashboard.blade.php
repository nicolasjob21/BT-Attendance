<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Dashboard</h1>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6">

        {{-- Greeting hero --}}
        <div class="relative overflow-hidden rounded-xs bg-linear-to-r from-brand-700 via-brand-600 to-accent-500 p-6 text-white shadow-xs sm:p-8">
            <div class="absolute -right-8 -top-10 h-40 w-40 rounded-full bg-white/10"></div>
            <div class="absolute -bottom-14 right-24 h-40 w-40 rounded-full bg-accent-500/20"></div>
            <div class="relative flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-white/80">{{ now()->format('l, F j, Y') }}</p>
                    <h2 class="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">
                        Welcome back, {{ $employee?->first_name ?? auth()->user()->name }}
                    </h2>
                    <p class="mt-1 text-sm text-white/80">
                        @if($employee)
                            {{ $employee->employee_type === 'technical' ? 'Technical staff' : 'Admin staff' }}
                            @if($employee->schedule) · {{ $employee->schedule->name }} @endif
                        @endif
                    </p>
                </div>
                <a href="{{ route('attendance.create') }}"
                   class="inline-flex items-center gap-2 rounded-xs bg-white px-4 py-2.5 text-sm font-semibold text-brand-700 shadow-xs transition hover:bg-brand-50">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v4l2.5 2"/></svg>
                    {{ $isClockedIn ? 'Clock out' : 'Clock in now' }}
                </a>
            </div>
        </div>

        {{-- Personal stat tiles --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Today --}}
            <div class="card card-hover p-5">
                <div class="flex items-center gap-3">
                    <span class="icon-chip bg-brand-100 text-brand-600 dark:bg-brand-900/40 dark:text-brand-300">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v4l2.5 2"/></svg>
                    </span>
                    <span class="eyebrow">Today</span>
                </div>
                @if($isClockedIn)
                    <p class="mt-3 text-xl font-bold text-emerald-600 dark:text-emerald-400">Clocked in</p>
                    <p class="text-xs text-gray-500 dark:text-slate-400">since {{ $todayLog->logged_at->format('g:i A') }}</p>
                @elseif($todayLog)
                    <p class="mt-3 text-xl font-bold text-gray-700 dark:text-slate-200">Clocked out</p>
                    <p class="text-xs text-gray-500 dark:text-slate-400">at {{ $todayLog->logged_at->format('g:i A') }}</p>
                @else
                    <p class="mt-3 text-xl font-bold text-gray-400 dark:text-slate-500">Not clocked in</p>
                @endif
            </div>

            {{-- Pending leave --}}
            <div class="card card-hover p-5">
                <div class="flex items-center gap-3">
                    <span class="icon-chip bg-accent-100 text-accent-600 dark:bg-accent-900/30 dark:text-accent-300">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="2"/><path stroke-linecap="round" d="M4 9h16M8 3v4M16 3v4"/></svg>
                    </span>
                    <span class="eyebrow">My pending leave</span>
                </div>
                <p class="mt-3 text-3xl font-bold text-gray-900 dark:text-slate-100">{{ $myPendingLeave }}</p>
                <a href="{{ route('leave.index') }}" class="mt-1 inline-block text-sm font-medium text-brand-700 hover:underline dark:text-brand-300">View leave &rarr;</a>
            </div>

            {{-- Pending OT --}}
            <div class="card card-hover p-5">
                <div class="flex items-center gap-3">
                    <span class="icon-chip bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 9v6M9 12h6"/></svg>
                    </span>
                    <span class="eyebrow">My pending OT</span>
                </div>
                <p class="mt-3 text-3xl font-bold text-gray-900 dark:text-slate-100">{{ $myPendingOt }}</p>
                <a href="{{ route('overtime.index') }}" class="mt-1 inline-block text-sm font-medium text-brand-700 hover:underline dark:text-brand-300">View overtime &rarr;</a>
            </div>

            {{-- Approvals or attendance --}}
            @if($canApprove)
            <div class="card card-hover p-5">
                <div class="flex items-center gap-3">
                    <span class="icon-chip bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                    <span class="eyebrow">Awaiting your approval</span>
                </div>
                <p class="mt-3 text-3xl font-bold text-gray-900 dark:text-slate-100">{{ $pendingApprovals }}</p>
                <a href="{{ route('leave.index') }}" class="mt-1 inline-block text-sm font-medium text-amber-700 hover:underline dark:text-amber-300">Review requests &rarr;</a>
            </div>
            @else
            <div class="card card-hover p-5">
                <div class="flex items-center gap-3">
                    <span class="icon-chip bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></svg>
                    </span>
                    <span class="eyebrow">My attendance</span>
                </div>
                <a href="{{ route('attendance.index') }}" class="mt-3 inline-block text-sm font-medium text-brand-700 hover:underline dark:text-brand-300">View history &rarr;</a>
            </div>
            @endif
        </div>

        {{-- Management row --}}
        @if($canManage || $canPayroll)
        <div class="grid gap-4 sm:grid-cols-2">
            @if($canManage)
            <div class="card p-5">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="icon-chip bg-brand-100 text-brand-600 dark:bg-brand-900/40 dark:text-brand-300">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-4-4h-1m-4 5H2v-1a4 4 0 014-4h4a4 4 0 014 4v1zm-3-11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </span>
                        <div>
                            <p class="eyebrow">Active employees</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ $activeEmployees }}</p>
                        </div>
                    </div>
                    <a href="{{ route('employees.index') }}" class="btn btn-primary">Manage</a>
                </div>
            </div>
            @endif

            @if($canPayroll && $currentPeriod)
            <div class="card p-5">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="icon-chip bg-accent-100 text-accent-600 dark:bg-accent-900/30 dark:text-accent-300">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/></svg>
                        </span>
                        <div>
                            <p class="eyebrow">Current payroll period</p>
                            <p class="text-lg font-semibold text-gray-900 dark:text-slate-100">{{ $currentPeriod->label() }}</p>
                            <div class="mt-1"><x-status-badge :status="$currentPeriod->status" /></div>
                        </div>
                    </div>
                    <a href="{{ route('payroll.index') }}" class="btn btn-primary">Open</a>
                </div>
            </div>
            @endif
        </div>
        @endif

        {{-- Quick actions --}}
        <div class="card p-5">
            <p class="mb-3 text-sm font-semibold text-gray-900 dark:text-slate-100">Quick actions</p>
            <div class="grid gap-3 sm:grid-cols-3">
                <a href="{{ route('attendance.create') }}" class="flex items-center gap-3 rounded-xs border border-gray-200 p-3 transition hover:border-brand-300 hover:bg-brand-50/50 dark:border-slate-700 dark:hover:border-brand-700 dark:hover:bg-slate-700/40">
                    <span class="icon-chip bg-brand-100 text-brand-600 dark:bg-brand-900/40 dark:text-brand-300">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v4l2.5 2"/></svg>
                    </span>
                    <span class="text-sm font-medium text-gray-800 dark:text-slate-200">Clock in / out</span>
                </a>
                <a href="{{ route('leave.create') }}" class="flex items-center gap-3 rounded-xs border border-gray-200 p-3 transition hover:border-brand-300 hover:bg-brand-50/50 dark:border-slate-700 dark:hover:border-brand-700 dark:hover:bg-slate-700/40">
                    <span class="icon-chip bg-accent-100 text-accent-600 dark:bg-accent-900/30 dark:text-accent-300">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="2"/><path stroke-linecap="round" d="M4 9h16M8 3v4M16 3v4"/></svg>
                    </span>
                    <span class="text-sm font-medium text-gray-800 dark:text-slate-200">File leave</span>
                </a>
                <a href="{{ route('overtime.create') }}" class="flex items-center gap-3 rounded-xs border border-gray-200 p-3 transition hover:border-brand-300 hover:bg-brand-50/50 dark:border-slate-700 dark:hover:border-brand-700 dark:hover:bg-slate-700/40">
                    <span class="icon-chip bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 9v6M9 12h6"/></svg>
                    </span>
                    <span class="text-sm font-medium text-gray-800 dark:text-slate-200">File overtime</span>
                </a>
            </div>
        </div>

    </div>
</x-app-layout>
