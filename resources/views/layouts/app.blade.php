<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'BT Attendance') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/brite-fav.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/brite-fav.png') }}">

    {{-- Set theme before paint to avoid a flash --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('theme');
                if (t !== 'light') {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased text-gray-800 dark:text-slate-200">
@php
    $user = auth()->user();
    $role = $user?->getRoleNames()->first();
    $roleLabels = ['superadmin' => 'Super Admin (CEO)', 'hr' => 'HR', 'employee' => 'Employee'];
@endphp
<div
    x-data="{
        sidebar: false,
        collapsed: localStorage.getItem('sidebar_collapsed') === '1',
        dark: document.documentElement.classList.contains('dark'),
        toggleCollapse() { this.collapsed = !this.collapsed; localStorage.setItem('sidebar_collapsed', this.collapsed ? '1' : '0'); },
        toggleDark() { this.dark = !this.dark; document.documentElement.classList.toggle('dark', this.dark); localStorage.setItem('theme', this.dark ? 'dark' : 'light'); }
    }"
    class="min-h-screen bg-slate-100 dark:bg-ink">

    {{-- Mobile overlay --}}
    <div x-show="sidebar" x-cloak @click="sidebar = false"
         class="fixed inset-0 z-20 bg-gray-900/50 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside x-cloak
           :class="[sidebar ? 'translate-x-0' : '-translate-x-full', collapsed ? 'lg:w-16' : 'lg:w-64']"
           class="fixed inset-y-0 left-0 z-30 flex w-64 transform flex-col border-r border-hair bg-deep text-slate-300 transition-all duration-200 lg:translate-x-0">

        {{-- Brand --}}
        <div class="flex shrink-0 items-center justify-center gap-2 border-b border-hair px-3 py-6">
            <img src="{{ asset('images/brite-fav.png') }}" alt="Brite-Tech" class="hidden h-10 w-10 shrink-0"
                 :class="collapsed ? 'lg:block' : ''">
            <span :class="collapsed ? 'lg:hidden' : ''" class="flex items-center justify-center">
                <img src="{{ asset('images/brite-logo.png') }}" alt="Brite-Tech" class="h-12 w-auto">
            </span>
        </div>

        {{-- Nav --}}
        <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
            <div class="space-y-1">
                <x-nav-item :active="request()->routeIs('dashboard')" :href="route('dashboard')" icon="grid">Dashboard</x-nav-item>
            </div>

            <div>
                <p :class="collapsed ? 'lg:hidden' : ''" class="px-3 mb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Self-service</p>
                <div class="space-y-1">
                    <x-nav-item :active="request()->routeIs('attendance.create')" :href="route('attendance.create')" icon="clock">Clock In / Out</x-nav-item>
                    <x-nav-item :active="request()->routeIs('attendance.index')" :href="route('attendance.index')" icon="list">My Attendance</x-nav-item>
                    <x-nav-item :active="request()->routeIs('leave.*')" :href="route('leave.index')" icon="calendar">Leave</x-nav-item>
                    <x-nav-item :active="request()->routeIs('overtime.*')" :href="route('overtime.index')" icon="plus-clock">Overtime</x-nav-item>
                </div>
            </div>

            @if($user?->can('manage employees') || $user?->can('run payroll') || $user?->can('view team reports'))
            <div>
                <p :class="collapsed ? 'lg:hidden' : ''" class="px-3 mb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Management</p>
                <div class="space-y-1">
                    @can('view team reports')
                        <x-nav-item :active="request()->routeIs('attendance.monitor')" :href="route('attendance.monitor')" icon="list">Attendance Log</x-nav-item>
                    @endcan
                    @can('manage employees')
                        <x-nav-item :active="request()->routeIs('employees.*')" :href="route('employees.index')" icon="users">Employees</x-nav-item>
                    @endcan
                    @can('run payroll')
                        <x-nav-item :active="request()->routeIs('payroll.index')" :href="route('payroll.index')" icon="cash">Payroll</x-nav-item>
                    @endcan
                </div>
            </div>
            @endif
        </nav>

        {{-- Collapse toggle (desktop only) --}}
        <div class="hidden shrink-0 border-t border-hair p-2 lg:block">
            <button @click="toggleCollapse()" class="flex w-full items-center gap-3 rounded-none px-3 py-2 text-sm text-slate-400 hover:bg-brand-500/10 hover:text-white">
                <svg class="h-5 w-5 shrink-0 transition-transform" :class="collapsed && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7M18 19l-7-7 7-7"/></svg>
                <span :class="collapsed ? 'lg:hidden' : ''">Collapse</span>
            </button>
        </div>
    </aside>

    {{-- Main column --}}
    <div :class="collapsed ? 'lg:pl-16' : 'lg:pl-64'" class="transition-all duration-200">
        {{-- Top bar --}}
        <header class="sticky top-0 z-10 flex h-16 items-center gap-3 border-b border-gray-200 bg-white/90 px-4 backdrop-blur dark:border-hair dark:bg-deep/80 sm:px-6">
            <button @click="sidebar = true" class="lg:hidden text-gray-500 hover:text-gray-700 dark:text-slate-400" aria-label="Open menu">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>

            <div class="flex-1 min-w-0">
                @isset($header)
                    <div class="truncate">{{ $header }}</div>
                @endisset
            </div>

            {{-- Dark mode toggle --}}
            <button @click="toggleDark()" class="rounded-xs p-2 text-gray-500 hover:bg-gray-100 dark:text-slate-400 dark:hover:bg-slate-700" :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'">
                <svg x-show="!dark" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36 6.36l-.7-.7M6.34 6.34l-.7-.7m12.72 0l-.7.7M6.34 17.66l-.7.7M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                <svg x-show="dark" x-cloak class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            </button>

            {{-- User menu --}}
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" class="flex items-center gap-2 rounded-xs px-2 py-1.5 hover:bg-gray-100 dark:hover:bg-slate-700">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-brand-100 text-brand-700 font-semibold text-sm dark:bg-brand-900 dark:text-brand-200">
                        {{ strtoupper(substr($user?->name ?? '?', 0, 1)) }}
                    </span>
                    <span class="hidden text-left sm:block leading-tight">
                        <span class="block text-sm font-medium text-gray-800 dark:text-slate-100">{{ $user?->name }}</span>
                        <span class="block text-xs text-brand-700 dark:text-brand-300">{{ $roleLabels[$role] ?? ucfirst($role ?? '') }}</span>
                    </span>
                    <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div x-show="open" x-cloak @click.outside="open = false"
                     class="absolute right-0 mt-2 w-48 rounded-xs border border-gray-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-800">
                    <a href="{{ route('profile.edit') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-700">Profile</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-700">Log out</button>
                    </form>
                </div>
            </div>
        </header>

        {{-- Flash message --}}
        @if(session('status'))
            <div class="mx-4 mt-4 rounded-xs border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:border-brand-800 dark:bg-brand-900/30 dark:text-brand-200 sm:mx-6">
                {{ session('status') }}
            </div>
        @endif

        <main class="p-4 sm:p-6">
            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
