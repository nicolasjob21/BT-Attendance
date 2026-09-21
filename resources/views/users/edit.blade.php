<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Edit Account</h1>
    </x-slot>
    <x-slot name="back">{{ route('users.index') }}</x-slot>
    <x-slot name="backLabel">Back to User Management</x-slot>

    <div class="page-form space-y-4">
        <form method="POST" action="{{ route('users.update', $user) }}" class="space-y-6 card p-6">
            @csrf
            @method('PUT')

            <div class="flex items-center gap-4">
                @if($user->profile_photo_url)
                    <img src="{{ $user->profile_photo_url }}" alt="" class="h-14 w-14 rounded-full object-cover ring-1 ring-gray-200 dark:ring-slate-700">
                @else
                    <span class="grid h-14 w-14 place-items-center rounded-full bg-brand-100 text-xl font-semibold text-brand-700 dark:bg-brand-900 dark:text-brand-200">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                @endif
                <div class="text-sm text-gray-600 dark:text-slate-300">
                    <div>Last sign-in: <span class="text-gray-900 dark:text-slate-100">{{ $user->last_login_at?->format('M j, Y g:i A') ?? 'Never' }}</span>@if($user->last_login_ip) <span class="text-gray-400 dark:text-slate-500">from {{ $user->last_login_ip }}</span>@endif</div>
                    <div>Last seen: <span class="text-gray-900 dark:text-slate-100">{{ $user->last_seen_at?->diffForHumans() ?? 'Never' }}</span></div>
                    <div>Created: <span class="text-gray-900 dark:text-slate-100">{{ $user->created_at?->format('M j, Y') }}</span>@if($user->employee) · Employee {{ $user->employee->employee_no }}@endif</div>
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="name" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Full name</label>
                    <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required
                           class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="username" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Username (login)</label>
                    <input type="text" id="username" name="username" value="{{ old('username', $user->username) }}" required autocapitalize="none" spellcheck="false"
                           class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Company prefix + first name, e.g. <code>brite-juan</code>.</p>
                    @error('username') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required
                           class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('email') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="role" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">System role</label>
                    <select id="role" name="role" required
                            class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                        @foreach($roles as $r => $label)
                            <option value="{{ $r }}" @selected(old('role', $user->roles->first()?->name) === $r)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('role') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">New password <span class="text-gray-400 dark:text-slate-500">(leave blank to keep)</span></label>
                    <input type="password" id="password" name="password" autocomplete="new-password" minlength="8"
                           class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('password') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="form-footer border-t border-gray-100 dark:border-slate-700 pt-5">
                @if($user->employee)
                    <a href="{{ route('employees.edit', $user->employee) }}" class="text-xs text-brand-700 hover:underline dark:text-brand-300">Edit employee profile (salary, schedule, site) →</a>
                @else
                    <span class="hidden sm:block"></span>
                @endif
                <div class="form-footer-actions">
                    <a href="{{ route('users.index') }}" class="btn-app btn-md btn-secondary">Cancel</a>
                    <button type="submit" class="btn-app btn-md btn-brand">Save changes</button>
                </div>
            </div>
        </form>

        @unless($user->is(auth()->user()))
            <div class="card p-6">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Sign-in access</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-slate-300">
                    @if($user->isDisabled())
                        This account has been disabled since {{ $user->disabled_at->format('M j, Y g:i A') }}. {{ $user->name }} cannot sign in.
                    @else
                        Disabling blocks sign-in immediately and ends any open session. Nothing is deleted.
                    @endif
                </p>
                @if($errors->has('account'))
                    <p class="mt-2 text-sm text-rose-600">{{ $errors->first('account') }}</p>
                @endif
                <div class="mt-3">
                    @if($user->isDisabled())
                        <x-confirm-action :action="route('users.disabled', $user)" method="PATCH" title="Enable this account?"
                            message="{{ $user->name }} will be able to sign in again." button="Enable sign-in" tone="emerald" size="md" />
                    @else
                        <x-confirm-action :action="route('users.disabled', $user)" method="PATCH" title="Disable this account?"
                            message="{{ $user->name }} will be signed out now and blocked from signing in until re-enabled." button="Disable sign-in" tone="amber" size="md" />
                    @endif
                </div>
            </div>
        @endunless
    </div>
</x-app-layout>
