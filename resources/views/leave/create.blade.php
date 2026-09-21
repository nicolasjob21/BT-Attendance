<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">File Leave</h1>
    </x-slot>
    <x-slot name="back">{{ route('leave.index') }}</x-slot>
    <x-slot name="backLabel">Back to Leave</x-slot>

    <div class="page-form form-split" x-data="{ portion: @js(old('day_portion', 'full')), get half() { return this.portion !== 'full' } }">
        <form method="POST" action="{{ route('leave.store') }}" class="space-y-5 card p-6">
            @csrf
            <div class="border-b border-gray-100 pb-4 dark:border-slate-700">
                <h2 class="font-display text-base font-bold text-gray-900 dark:text-slate-100">Leave request</h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Goes to HR for approval. You will be notified when it is decided.</p>
            </div>

            <div>
                <label for="leave_type_id" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">
                    Leave type <span class="font-normal text-gray-400 dark:text-slate-500" x-show="half" x-cloak>(optional for a half day)</span>
                </label>
                <select id="leave_type_id" name="leave_type_id" :required="!half"
                        class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Select…</option>
                    @foreach($leaveTypes as $type)
                        <option value="{{ $type->id }}" @selected(old('leave_type_id') == $type->id)>
                            {{ $type->name }}@if($type->code) ({{ $type->code }})@endif
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400" x-show="half" x-cloak>No leave type needed — put the details (e.g. the client) in the reason below.</p>
                @error('leave_type_id') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="day_portion" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Duration</label>
                <select id="day_portion" name="day_portion" x-model="portion" required
                        class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="full">Full day</option>
                    <option value="half_am">Half day — Morning</option>
                    <option value="half_pm">Half day — Afternoon</option>
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400" x-show="half" x-cloak>A half day counts as 0.5 day on the selected date.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="date_from" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200" x-text="half ? 'Date' : 'From'">From</label>
                    <input type="date" id="date_from" name="date_from" value="{{ old('date_from') }}" required
                           class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('date_from') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div x-show="!half" x-cloak>
                    <label for="date_to" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">To</label>
                    <input type="date" id="date_to" name="date_to" value="{{ old('date_to') }}" :required="!half" :disabled="half"
                           class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('date_to') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="reason" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Reason <span class="text-gray-400 dark:text-slate-500">(optional)</span></label>
                <textarea id="reason" name="reason" rows="3"
                          class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('reason') }}</textarea>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('leave.index') }}" class="btn-app btn-md btn-secondary">Cancel</a>
                <button type="submit" class="btn-app btn-md btn-brand">Submit request</button>
            </div>
        </form>

        <x-form-aside title="How leave works" :facts="$facts">
            <li><b class="text-gray-800 dark:text-slate-100">Full day</b> — pick the leave type and the dates; consecutive days count once each.</li>
            <li><b class="text-gray-800 dark:text-slate-100">Half day</b> — morning or afternoon; counted as 0.5 day and excuses the missing half in payroll once approved.</li>
            <li><b class="text-gray-800 dark:text-slate-100">Leaving early or sick today?</b> Use <a href="{{ route('leave.early.create') }}" class="font-medium text-brand-700 hover:underline dark:text-brand-300">Go home early / Sick</a> instead — it records your planned time out.</li>
            <li>Unapproved absences and undertime are deducted at your daily / per-minute rate, so file before the day when you can.</li>
        </x-form-aside>
    </div>
</x-app-layout>
