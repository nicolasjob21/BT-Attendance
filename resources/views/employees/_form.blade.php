@php $employee = $employee ?? null; @endphp

<div class="grid gap-5 sm:grid-cols-2">
    <div>
        <label for="first_name" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">First name</label>
        <input type="text" id="first_name" name="first_name" value="{{ old('first_name', $employee?->first_name) }}" required
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
        @error('first_name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="last_name" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Last name</label>
        <input type="text" id="last_name" name="last_name" value="{{ old('last_name', $employee?->last_name) }}" required
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
        @error('last_name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="username" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Username (login)</label>
        <input type="text" id="username" name="username" value="{{ old('username', $employee?->user?->username) }}"
               placeholder="{{ \App\Models\User::USERNAME_PREFIX }}-firstname" autocapitalize="none" spellcheck="false"
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
        <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Company prefix + first name, e.g. <code>brite-juan</code>. Leave blank to generate it automatically.</p>
        @error('username') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="email" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Email</label>
        <input type="email" id="email" name="email" value="{{ old('email', $employee?->email) }}" required
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
        @error('email') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="phone" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Phone <span class="text-gray-400 dark:text-slate-500">(optional)</span></label>
        <input type="text" id="phone" name="phone" value="{{ old('phone', $employee?->phone) }}"
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
    </div>

    <div>
        <label for="employee_type" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Employee type</label>
        <select id="employee_type" name="employee_type" required
                class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="admin" @selected(old('employee_type', $employee?->employee_type) === 'admin')>Admin (fixed shift)</option>
            <option value="technical" @selected(old('employee_type', $employee?->employee_type) === 'technical')>Technical (flexible / field)</option>
        </select>
    </div>
    <div>
        <span class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Account role</span>
        @php $currentRole = $employee?->user?->getRoleNames()->first() ?? \App\Support\RoleMatrix::EMPLOYEE; @endphp
        <div class="flex h-[42px] items-center gap-2 rounded-xs border border-dashed border-gray-300 px-3 text-sm text-gray-700 dark:border-slate-600 dark:text-slate-200">
            <span class="badge {{ $currentRole === 'employee' ? 'badge-neutral' : 'badge-info' }}">{{ \App\Support\RoleMatrix::ROLE_LABELS[$currentRole] ?? ucfirst($currentRole) }}</span>
            <span class="text-xs text-gray-500 dark:text-slate-400">
                @if($employee)
                    Roles are changed in <a href="{{ \Route::has('users.index') ? route('users.index') : '#' }}" class="underline">User Management</a>.
                @else
                    New employees always get the Employee role. Super Admin, Admin and Developer accounts are set in User Management.
                @endif
            </span>
        </div>
    </div>

    <div>
        <label for="schedule_id" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Schedule</label>
        <select id="schedule_id" name="schedule_id"
                class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="">— None —</option>
            @foreach($schedules as $sched)
                <option value="{{ $sched->id }}" @selected(old('schedule_id', $employee?->schedule_id) == $sched->id)>{{ $sched->name }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="monthly_salary" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Monthly salary (₱)</label>
        <input type="number" step="0.01" min="0" id="monthly_salary" name="monthly_salary"
               value="{{ old('monthly_salary', $employee?->monthly_salary) }}" required
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
        @error('monthly_salary') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="date_hired" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Date hired <span class="text-gray-400 dark:text-slate-500">(optional)</span></label>
        <input type="date" id="date_hired" name="date_hired" value="{{ old('date_hired', optional($employee?->date_hired)->format('Y-m-d')) }}"
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
    </div>

    <div>
        <label for="status" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Status</label>
        <select id="status" name="status" required
                class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
            @foreach(['active' => 'Active', 'inactive' => 'Inactive', 'on_leave' => 'On leave'] as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $employee?->status ?? 'active') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div x-data="{ show: false }">
        <label for="password" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">
            {{ $employee ? 'Reset password' : 'Temporary password' }}
            <span class="text-gray-400 dark:text-slate-500">({{ $employee ? 'leave blank to keep' : 'blank = auto-generate' }})</span>
        </label>
        <div class="flex gap-2">
            <input :type="show ? 'text' : 'password'" id="password" name="password" x-ref="pw"
                   class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
            <button type="button" @click="$refs.pw.value = Math.random().toString(36).slice(-10); show = true"
                    class="shrink-0 rounded-xs border border-gray-300 dark:border-slate-600 px-3 text-sm text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700/60">Generate</button>
        </div>
        @error('password') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>
</div>
