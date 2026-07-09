<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">File Overtime</h1>
    </x-slot>

    <div class="mx-auto max-w-xl" x-data="otForm()">
        <form method="POST" action="{{ route('overtime.store') }}" class="space-y-5 card p-6">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="ot_date" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Date</label>
                    <input type="date" id="ot_date" name="ot_date" value="{{ old('ot_date') }}" required
                           x-model="date" @change="fetchPreview()"
                           class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('ot_date') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">
                        Overtime hours <span class="font-normal text-gray-400 dark:text-slate-500">(auto-calculated)</span>
                    </label>
                    <div class="flex h-[38px] items-center gap-2 rounded-xs border border-gray-300 bg-gray-50 px-3 dark:border-slate-600 dark:bg-slate-800/60">
                        <span class="text-base font-semibold tabular-nums text-gray-900 dark:text-slate-100"
                              x-text="loading ? '…' : (hours !== null ? hours + ' h' : '—')"></span>
                        <span class="text-xs text-gray-400 dark:text-slate-500" x-show="!loading && hours === null">select a date</span>
                    </div>
                </div>
            </div>

            {{-- Auto-calc explanation --}}
            <p class="-mt-2 text-xs" :class="ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-slate-400'"
               x-show="message" x-text="message" x-cloak></p>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">
                    Overtime type <span class="font-normal text-gray-400 dark:text-slate-500">(auto-classified from the date)</span>
                </label>
                <div class="flex h-[38px] items-center rounded-xs border border-gray-300 bg-gray-50 px-3 text-sm dark:border-slate-600 dark:bg-slate-800/60">
                    <span class="font-medium text-gray-900 dark:text-slate-100" x-text="otTypeLabel || '—'"></span>
                </div>
            </div>

            <div>
                <label for="reason" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Reason <span class="text-gray-400 dark:text-slate-500">(optional)</span></label>
                <textarea id="reason" name="reason" rows="3"
                          class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('reason') }}</textarea>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('overtime.index') }}" class="rounded-xs border border-gray-300 dark:border-slate-600 px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700/60">Cancel</a>
                <button type="submit" :disabled="!ok || loading"
                        class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-4 py-2 text-sm font-semibold text-white hover:from-brand-700 hover:to-accent-600 disabled:cursor-not-allowed disabled:opacity-50">Submit request</button>
            </div>
        </form>
    </div>

    <script>
        function otForm() {
            return {
                date: @json(old('ot_date') ?? ''),
                hours: null,
                otTypeLabel: '',
                ok: false,
                loading: false,
                message: '',

                init() {
                    if (this.date) this.fetchPreview();
                },

                async fetchPreview() {
                    if (!this.date) { this.hours = null; this.otTypeLabel = ''; this.ok = false; this.message = ''; return; }
                    this.loading = true;
                    try {
                        const url = new URL(@json(route('overtime.preview')), window.location.origin);
                        url.searchParams.set('date', this.date);
                        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) throw new Error('lookup failed');
                        const data = await res.json();
                        this.hours = data.hours;
                        this.otTypeLabel = data.ot_type_label;
                        this.ok = data.ok;
                        this.message = data.message;
                    } catch (e) {
                        this.hours = null; this.otTypeLabel = ''; this.ok = false;
                        this.message = 'Could not calculate overtime for that date. Try again.';
                    } finally {
                        this.loading = false;
                    }
                },
            };
        }
    </script>
</x-app-layout>
