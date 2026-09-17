<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'BT Attendance') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/brite-fav.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/brite-fav.png') }}">

    {{-- Set theme and sidebar state before paint so neither flashes or slides on load --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('theme');
                if (t !== 'light') {
                    document.documentElement.classList.add('dark');
                }
                if (localStorage.getItem('sidebar_collapsed') === '1') {
                    document.documentElement.classList.add('sidebar-collapsed');
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
    $roleLabels = \App\Support\RoleMatrix::ROLE_LABELS;
@endphp
<div
    x-data="{
        sidebar: false,
        collapsed: document.documentElement.classList.contains('sidebar-collapsed'),
        dark: document.documentElement.classList.contains('dark'),
        init() { requestAnimationFrame(() => document.documentElement.classList.add('sidebar-ready')); },
        toggleCollapse() {
            this.collapsed = !this.collapsed;
            document.documentElement.classList.toggle('sidebar-collapsed', this.collapsed);
            localStorage.setItem('sidebar_collapsed', this.collapsed ? '1' : '0');
        },
        toggleDark() { this.dark = !this.dark; document.documentElement.classList.toggle('dark', this.dark); localStorage.setItem('theme', this.dark ? 'dark' : 'light'); }
    }"
    class="min-h-screen bg-slate-100 dark:bg-ink">

    {{-- Mobile overlay --}}
    <div x-show="sidebar" x-cloak @click="sidebar = false"
         class="fixed inset-0 z-20 bg-gray-900/50 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside :class="sidebar ? 'translate-x-0' : '-translate-x-full'"
           class="app-sidebar fixed inset-y-0 left-0 z-30 flex w-64 -translate-x-full transform flex-col border-r border-gray-200 bg-white text-gray-600 lg:translate-x-0 dark:border-hair dark:bg-deep dark:text-slate-300">

        {{-- Brand --}}
        <div class="flex h-16 shrink-0 items-center justify-center gap-2 border-b border-gray-200 px-3 dark:border-hair">
            <img src="{{ asset('images/brite-fav.png') }}" alt="Brite-Tech" class="sb-mini hidden h-9 w-9 shrink-0 object-contain">
            <span class="sb-label flex items-center justify-center">
                <img src="{{ asset('images/brite-logo.png') }}" alt="Brite-Tech" class="h-8 w-auto max-w-full object-contain">
            </span>
        </div>

        {{-- Nav --}}
        <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
            <div class="space-y-1">
                <x-nav-item :active="request()->routeIs('dashboard')" :href="route('dashboard')" icon="grid">Dashboard</x-nav-item>
            </div>

            @php
                // Superadmin is management-only: no self-service section at all.
                $selfService = $user?->canAny(['clock attendance', 'request leave', 'request overtime', 'approve requests']);
            @endphp
            @if($selfService)
            <div>
                <p class="sb-label px-3 mb-1 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-slate-500">{{ $user->can('clock attendance') ? 'Self-service' : 'Approvals' }}</p>
                <div class="space-y-1">
                    @can('clock attendance')
                        <x-nav-item :active="request()->routeIs('attendance.create')" :href="route('attendance.create')" icon="clock">Clock In / Out</x-nav-item>
                        <x-nav-item :active="request()->routeIs('attendance.index')" :href="route('attendance.index')" icon="list">My Attendance</x-nav-item>
                    @endcan
                    @if($user->canAny(['request leave', 'approve requests']))
                        <x-nav-item :active="request()->routeIs('leave.*')" :href="route('leave.index')" icon="calendar">Leave</x-nav-item>
                    @endif
                    @if($user->canAny(['request overtime', 'approve requests']))
                        <x-nav-item :active="request()->routeIs('overtime.*')" :href="route('overtime.index')" icon="plus-clock">Overtime</x-nav-item>
                    @endif
                    @if($user->employee && $user->can('clock attendance'))
                        @php $openCheckpoints = \App\Models\Checkpoint::where('employee_id', $user->employee->id)->whereIn('status', \App\Models\Checkpoint::WAITING_STATUSES)->whereHas('campaign', fn ($q) => $q->where('status', 'active'))->count(); @endphp
                        <x-nav-item :active="request()->routeIs('my-checkpoints.*')" :href="route('my-checkpoints.index')" icon="shield-check" :badge="$openCheckpoints ?: null">My Checkpoints</x-nav-item>
                    @endif
                </div>
            </div>
            @endif

            @if($user?->canAny(['manage employees', 'run payroll', 'view team reports', 'manage settings', 'manage sites', 'view checkpoint module', 'manage users', 'manage roles']))
            <div>
                <p class="sb-label px-3 mb-1 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-slate-500">Management</p>
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
                    @can('view checkpoint module')
                        <x-nav-item :active="request()->routeIs('checkpoints.*')" :href="route('checkpoints.index')" icon="shield-check">Check Point</x-nav-item>
                    @endcan
                    @if($user->canAny(['manage settings', 'manage sites']))
                        <x-nav-item :active="request()->routeIs('sites.*')" :href="route('sites.index')" icon="map-pin">Locations</x-nav-item>
                    @endif
                    @if($user->canAny(['manage users', 'manage roles']))
                        <x-nav-item :active="request()->routeIs('users.*') || request()->routeIs('roles.*')" :href="$user->can('manage users') ? route('users.index') : route('roles.index')" icon="user-cog">User Management</x-nav-item>
                    @endif
                </div>
            </div>
            @endif
        </nav>

        {{-- Collapse toggle (desktop only) --}}
        <div class="hidden shrink-0 border-t border-gray-200 p-2 lg:block dark:border-hair">
            <button @click="toggleCollapse()" class="flex w-full items-center gap-3 rounded-none px-3 py-2 text-sm text-gray-500 hover:bg-brand-50 hover:text-brand-700 dark:text-slate-400 dark:hover:bg-brand-500/10 dark:hover:text-white">
                <svg class="h-5 w-5 shrink-0 transition-transform" :class="collapsed && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7M18 19l-7-7 7-7"/></svg>
                <span class="sb-label">Collapse</span>
            </button>
        </div>
    </aside>

    {{-- Main column --}}
    <div class="app-main">
        {{-- Top bar --}}
        {{-- Pages can set <x-slot name="immersive">1</x-slot> to hide the top bar on phones
             (full-screen camera stages that bring their own controls). --}}
        <header class="{{ isset($immersive) ? 'hidden sm:flex' : 'flex' }} sticky top-0 z-10 h-16 items-center gap-3 border-b border-gray-200 bg-white/90 px-4 backdrop-blur dark:border-hair dark:bg-deep/80 sm:px-6">
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

            {{-- Notifications --}}
            @php
                $notifs = $user ? $user->notifications()->latest()->take(8)->get() : collect();
                $unreadCount = $user ? $user->unreadNotifications()->count() : 0;
            @endphp
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" class="relative rounded-xs p-2 text-gray-500 hover:bg-gray-100 dark:text-slate-400 dark:hover:bg-slate-700" aria-label="Notifications">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    @if($unreadCount)
                        <span class="absolute -top-0.5 -right-0.5 grid h-4 min-w-[1rem] place-items-center rounded-full bg-accent-500 px-1 text-[10px] font-bold leading-none text-white">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
                    @endif
                </button>
                <div x-show="open" x-cloak @click.outside="open = false"
                     class="fixed inset-x-2 top-[4.25rem] z-20 w-auto overflow-hidden rounded-xs border border-gray-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-800 sm:absolute sm:inset-x-auto sm:right-0 sm:top-auto sm:mt-2 sm:w-80">
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-slate-700">
                        <span class="text-sm font-semibold text-gray-800 dark:text-slate-100">Notifications</span>
                        @if($unreadCount)
                            <form method="POST" action="{{ route('notifications.read-all') }}">
                                @csrf
                                <button class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-300">Mark all read</button>
                            </form>
                        @endif
                    </div>
                    <div class="max-h-96 overflow-y-auto">
                        @forelse($notifs as $n)
                            @php $unread = is_null($n->read_at); $kind = $n->data['kind'] ?? 'request'; @endphp
                            <a href="{{ route('notifications.open', $n->id) }}"
                               class="flex gap-3 border-b border-gray-50 px-4 py-3 hover:bg-gray-50 dark:border-slate-700/60 dark:hover:bg-slate-700/50 {{ $unread ? 'bg-brand-50/60 dark:bg-brand-900/10' : '' }}">
                                <span class="mt-1.5 h-2 w-2 flex-none rounded-full {{ $kind === 'approved' ? 'bg-emerald-500' : ($kind === 'rejected' ? 'bg-rose-500' : 'bg-brand-500') }} {{ $unread ? '' : 'opacity-30' }}"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-gray-800 dark:text-slate-100">{{ $n->data['title'] ?? 'Notification' }}</span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-slate-400">{{ $n->data['message'] ?? '' }}</span>
                                    <span class="mt-0.5 block text-[11px] text-gray-400 dark:text-slate-500">{{ $n->created_at->diffForHumans() }}</span>
                                </span>
                            </a>
                        @empty
                            <p class="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">No notifications yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- User menu --}}
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" class="flex items-center gap-2 rounded-xs px-2 py-1.5 hover:bg-gray-100 dark:hover:bg-slate-700">
                    @if($user?->profile_photo_url)
                        <img src="{{ $user->profile_photo_url }}" alt="{{ $user->name }}"
                             class="h-8 w-8 rounded-full object-cover ring-1 ring-gray-200 dark:ring-slate-700">
                    @else
                        <span class="grid h-8 w-8 place-items-center rounded-full bg-brand-100 text-brand-700 font-semibold text-sm dark:bg-brand-900 dark:text-brand-200">
                            {{ strtoupper(substr($user?->name ?? '?', 0, 1)) }}
                        </span>
                    @endif
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

        {{-- Floating back button, bottom-right (pages that set the "back" slot) --}}
        @isset($back)
            <x-back-button :href="trim($back)" :label="trim($backLabel ?? 'Back')" />
        @endisset

        <main class="{{ isset($immersive) ? 'p-0 sm:p-6' : 'p-4 sm:p-6' }} lg:px-8 lg:py-7">
            {{ $slot }}
        </main>
    </div>

    {{-- Live presence checkpoint alert: polls the server and pops a modal +
         browser notification the moment HR activates a checkpoint. The
         server-side deadline is the only official one; this is a heads-up. --}}
    @if($user?->employee && ! request()->routeIs('my-checkpoints.show'))
        <div x-data="checkpointAlert({{ (int) config('checkpoints.poll_seconds', 20) }})">
            <div x-show="cp && !dismissed" x-cloak class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center" @keydown.escape.window="dismissed = true">
                <div class="fixed inset-0 bg-gray-900/70 backdrop-blur-sm"></div>
                <div class="relative w-full max-w-md overflow-hidden rounded-xs border-2 border-accent-500 bg-white shadow-2xl dark:bg-surface" role="alertdialog" aria-live="assertive">
                    <div class="flex items-center gap-3 bg-accent-500 px-5 py-3 text-white">
                        <svg class="h-6 w-6 shrink-0 animate-pulse" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/></svg>
                        <div class="min-w-0 flex-1">
                            <p class="font-display text-sm font-bold uppercase tracking-wider">Live presence checkpoint active</p>
                            <p class="text-xs text-white/85" x-text="cp?.site"></p>
                        </div>
                        <p class="font-display text-2xl font-extrabold tabular-nums" x-text="countdown()"></p>
                    </div>
                    <div class="space-y-3 px-5 py-4 text-sm text-gray-700 dark:text-slate-200">
                        <p x-text="cp?.message"></p>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                            <dt class="text-gray-500 dark:text-slate-400">Instruction</dt><dd class="font-medium text-gray-900 dark:text-slate-100" x-text="cp?.instruction"></dd>
                            <dt class="text-gray-500 dark:text-slate-400">Start</dt><dd class="tabular-nums" x-text="fmt(cp?.starts_at)"></dd>
                            <dt class="text-gray-500 dark:text-slate-400">Deadline</dt><dd class="tabular-nums font-semibold text-accent-700 dark:text-accent-300" x-text="fmt(cp?.expires_at)"></dd>
                        </dl>
                        <div class="flex gap-2 pt-1">
                            <a :href="cp?.url" class="btn-app btn-md btn-brand flex-1">Complete verification</a>
                            <button type="button" @click="dismissed = true" class="btn-app btn-md btn-secondary">Later</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script>
            function checkpointAlert(pollSeconds) {
                return {
                    cp: null, dismissed: false, seenId: null, offset: 0, secondsLeft: 0,
                    init() {
                        this.poll();
                        setInterval(() => this.poll(), pollSeconds * 1000);
                        setInterval(() => this.tick(), 1000);
                        window.addEventListener('online', () => this.poll());
                    },
                    async poll() {
                        try {
                            const r = await fetch(@json(route('my-checkpoints.active')), { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                            if (!r.ok) return;
                            const d = await r.json();
                            this.offset = new Date(d.server_now).getTime() - Date.now();
                            if (d.active && d.active.id !== this.seenId) {
                                this.seenId = d.active.id; this.dismissed = false; this.notify(d.active);
                            }
                            this.cp = d.active;
                            this.tick();
                        } catch (e) { /* offline — keep last state */ }
                    },
                    tick() {
                        if (!this.cp) return;
                        this.secondsLeft = Math.max(0, Math.floor((new Date(this.cp.expires_at).getTime() - (Date.now() + this.offset)) / 1000));
                        if (this.secondsLeft === 0) this.cp = null;
                    },
                    countdown() { return Math.floor(this.secondsLeft / 60) + ':' + String(this.secondsLeft % 60).padStart(2, '0'); },
                    fmt(iso) { return iso ? new Date(iso).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'Asia/Manila' }) : ''; },
                    // Browser notification when permission was granted (see My Checkpoints page).
                    notify(cp) {
                        if (!('Notification' in window) || Notification.permission !== 'granted') return;
                        try {
                            const n = new Notification('Live presence checkpoint active', { body: cp.message + ' ' + cp.site + ' — ' + cp.instruction, tag: 'checkpoint-' + cp.id, requireInteraction: true });
                            n.onclick = () => { window.focus(); location.href = cp.url; };
                        } catch (e) {}
                    },
                };
            }
        </script>
    @endif

    {{-- Image lightbox (click any photo to enlarge) --}}
    <div x-data="{ src: null }"
         x-on:open-lightbox.window="src = $event.detail"
         x-show="src" x-cloak
         @click="src = null"
         @keydown.escape.window="src = null"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-lg"
         x-transition.opacity>
        <img :src="src" alt="Enlarged photo"
             class="max-h-[90vh] max-w-[90vw] rounded-lg object-contain shadow-2xl">
        <button @click="src = null" aria-label="Close"
                class="absolute right-4 top-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>
</div>
</body>
</html>
