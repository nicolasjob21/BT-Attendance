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
        <label for="payout_method" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Salary payout</label>
        <select id="payout_method" name="payout_method" required x-data x-on:change="$dispatch('payout', $event.target.value)"
                class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
            @foreach(\App\Models\Employee::PAYOUT_METHODS as $v => $l)
                <option value="{{ $v }}" @selected(old('payout_method', $employee?->payout_method ?? 'cash') === $v)>{{ $l }}</option>
            @endforeach
        </select>
        @error('payout_method') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="bank_account_no" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Card / account no. <span class="text-gray-400 dark:text-slate-500">(card payout only)</span></label>
        <input type="text" id="bank_account_no" name="bank_account_no" value="{{ old('bank_account_no', $employee?->bank_account_no) }}" maxlength="40"
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
        @error('bank_account_no') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="phone" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Phone <span class="text-gray-400 dark:text-slate-500">(optional)</span></label>
        <input type="text" id="phone" name="phone" value="{{ old('phone', $employee?->phone) }}"
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
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
