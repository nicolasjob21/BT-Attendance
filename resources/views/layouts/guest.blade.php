<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'BT Attendance') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('images/brite-fav.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/brite-fav.png') }}">

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
    <body class="font-sans text-gray-900 antialiased dark:text-slate-100">
        <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-slate-100 px-4 py-12 dark:bg-ink">
            {{-- Layered editorial backdrop: glows + faint grid --}}
            <div class="pointer-events-none absolute -top-40 -right-32 h-[30rem] w-[30rem] rounded-full bg-brand-500/15 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-40 -left-32 h-[30rem] w-[30rem] rounded-full bg-accent-500/15 blur-3xl"></div>
            <div class="pointer-events-none absolute inset-0 opacity-[0.03] dark:opacity-[0.05]"
                 style="background-image:linear-gradient(#7ec8e3 1px,transparent 1px),linear-gradient(90deg,#7ec8e3 1px,transparent 1px);background-size:44px 44px;"></div>
            <div class="pointer-events-none absolute left-1/2 top-1/2 h-[38rem] w-[38rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-brand-500/5 blur-3xl dark:bg-brand-500/[0.07]"></div>

            {{-- Centered login container --}}
            <div class="relative w-full max-w-md">
                {{-- Logo with soft glow --}}
                <div class="mb-8 flex justify-center">
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-0 -z-10 scale-125 rounded-full bg-brand-400/20 blur-2xl"></div>
                        <img src="{{ asset('images/brite-logo.png') }}" alt="Brite-Tech" class="h-14 w-auto drop-shadow-lg">
                    </div>
                </div>

                {{-- Card with gradient top accent --}}
                <div class="relative overflow-hidden rounded-xs border border-slate-900/10 bg-white shadow-xl shadow-slate-900/10 ring-1 ring-black/5 dark:border-hair dark:bg-surface dark:shadow-black/40 dark:ring-white/5">
                    <div class="brand-gradient h-1 w-full"></div>
                    <div class="p-8 sm:p-9">
                        <p class="eyebrow mb-2 text-center">Attendance &amp; Payroll</p>
                        <h2 class="mb-1.5 text-center font-display text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Sign in</h2>
                        <p class="mb-7 text-center text-sm text-gray-500 dark:text-slate-400">Welcome back — please enter your details.</p>
                        {{ $slot }}
                    </div>
                </div>

                <p class="mt-8 text-center text-xs text-gray-400 dark:text-slate-500">
                    &copy; {{ date('Y') }} Brite-Tech Solutions Inc. · Secure sign-in
                </p>
            </div>
        </div>
    </body>
</html>
