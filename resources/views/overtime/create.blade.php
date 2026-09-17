<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Request Overtime</h1>
    </x-slot>
    <x-slot name="back">{{ route('overtime.index') }}</x-slot>
    <x-slot name="backLabel">Back to Overtime</x-slot>

    <div class="mx-auto max-w-xl" x-data="otForm()">
        <form method="POST" action="{{ route('overtime.store') }}" class="space-y-5 card p-6">
            @csrf

            <p class="rounded-xs border border-brand-200 bg-brand-50 px-3 py-2 text-xs text-brand-800 dark:border-brand-800 dark:bg-brand-900/30 dark:text-brand-200" x-show="!isRestDay()">
                Request overtime <strong>before</strong> you work it. Once approved, your actual overtime hours are taken from your clock-out that day — up to the hours approved here. Overtime is paid one cutoff later (1–15 OT on the 16–30 payroll; 16–30 OT on the next 1–15 payroll).
            </p>

            <p class="rounded-xs border border-accent-200 bg-accent-50 px-3 py-2 text-xs text-accent-800 dark:border-accent-800 dark:bg-accent-900/30 dark:text-accent-200" x-show="isRestDay()" x-cloak>
                <strong>Rest-day work.</strong> Saturday and Sunday are days off, so <strong>every hour</strong> you work on site that day counts as overtime at 130%. Enter your planned shift (e.g. 8:00 AM – 5:00 PM) and what the deployment is for. Your actual hours come from your clock-in/out that day.
            </p>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="ot_date" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Date</label>
                    <input type="date" id="ot_date" name="ot_date" value="{{ old('ot_date', now()->toDateString()) }}" min="{{ $minDate }}" required
                           x-model="date" @change="applyDefaults()"
                           class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500 dark:[color-scheme:dark]">
                    @error('ot_date') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="planned_start" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200" x-text="isRestDay() ? 'Shift starts' : 'From'">From</label>
                    <input type="time" id="planned_start" name="planned_start" value="{{ old('planned_start', $defaultStart) }}" required
                           x-model="start"
                           class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500 dark:[color-scheme:dark]">
                    @error('planned_start') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="planned_end" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200" x-text="isRestDay() ? 'Shift ends' : 'To'">To</label>
                    <input type="time" id="planned_end" name="planned_end" value="{{ old('planned_end') }}" required
                           x-model="end"
                           class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500 dark:[color-scheme:dark]">
                    @error('planned_end') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Derived summary --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xs border border-gray-200 bg-gray-50 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800/60">
                <span class="text-gray-500 dark:text-slate-400">Requested:</span>
                <span class="font-semibold tabular-nums text-gray-900 dark:text-slate-100" x-text="hours !== null ? hours + ' h' : '—'"></span>
                <span class="text-gray-300 dark:text-slate-600">·</span>
                <span class="text-gray-700 dark:text-slate-200" x-text="typeLabel()"></span>
                <span class="text-xs text-rose-600" x-show="hours !== null && (hours <= 0 || hours > 12)" x-cloak>Window must be between 15 minutes and 12 hours.</span>
            </div>

            <div>
                <label for="reason" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200" x-text="isRestDay() ? 'What is the weekend deployment for? *' : 'What will you do during the overtime? *'">What will you do during the overtime? <span class="text-rose-500">*</span></label>
                <textarea id="reason" name="reason" rows="4" required minlength="10" maxlength="1000"
                          :placeholder="isRestDay() ? 'e.g. Saturday site work at Project Site A — client shutdown window for panel installation.' : 'e.g. Finish cable termination at Project Site A, 3rd floor — client inspection tomorrow morning.'"
                          class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('reason') }}</textarea>
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Your approver sees this when deciding. Be specific about the task and why it can't wait.</p>
                @error('reason') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('overtime.index') }}" class="btn-app btn-md btn-secondary">Cancel</a>
                <button type="submit" :disabled="!valid()"
                        class="btn-app btn-md btn-brand disabled:cursor-not-allowed">Send for approval</button>
            </div>
        </form>
    </div>

    <script>
        function otForm() {
            return {
                date: @json(old('ot_date', now()->toDateString())),
                start: @json(old('planned_start', $defaultStart)),
                end: @json(old('planned_end', '')),
                weekdayStart: @json($defaultStart),
                restDayStart: @json($restDayStart),
                restDayEnd: @json($restDayEnd),
                touched: @json((bool) old('planned_end')),

                isRestDay() {
                    if (!this.date) return false;
                    const d = new Date(this.date + 'T00:00:00').getDay();
                    return d === 0 || d === 6;
                },

                // Swap between "stay late" and "full shift" defaults until the
                // employee has edited the times themselves.
                applyDefaults() {
                    if (this.touched) return;
                    if (this.isRestDay()) { this.start = this.restDayStart; this.end = this.restDayEnd; }
                    else { this.start = this.weekdayStart; this.end = ''; }
                },

                init() {
                    this.applyDefaults();
                    this.$watch('start', () => this.touched = true);
                    this.$watch('end', () => this.touched = true);
                },

                // Mirrors the server: end before/equal start rolls over midnight.
                get hours() {
                    if (!this.start || !this.end) return null;
                    const [sh, sm] = this.start.split(':').map(Number);
                    const [eh, em] = this.end.split(':').map(Number);
                    let mins = (eh * 60 + em) - (sh * 60 + sm);
                    if (mins <= 0) mins += 24 * 60;
                    return Math.round(mins / 60 * 100) / 100;
                },

                typeLabel() {
                    if (!this.date) return '';
                    return this.isRestDay() ? 'Rest-day work · 130% (all hours)' : 'Regular OT · 125%';
                },

                valid() {
                    return this.date && this.hours !== null && this.hours > 0 && this.hours <= 12;
                },
            };
        }
    </script>
</x-app-layout>
