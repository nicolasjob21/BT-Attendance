<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Go Home Early / Sick Leave</h1>
    </x-slot>
    <x-slot name="back">{{ route('leave.index') }}</x-slot>
    <x-slot name="backLabel">Back to Leave</x-slot>

    <div class="page-form form-split">
        <form method="POST" action="{{ route('leave.early.store') }}" class="space-y-5 card p-6">
            @csrf

            <div class="rounded-xs border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-900/30 dark:text-amber-200">
                Feeling unwell mid-shift? File this to leave early for <span class="font-semibold">today</span>.
                It is recorded as a <span class="font-semibold">half-day (PM) sick leave</span> and goes to HR for approval.
            </div>

            <div>
                <label for="requested_time_out" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Requested time out</label>
                <input type="time" id="requested_time_out" name="requested_time_out"
                       value="{{ old('requested_time_out', now()->format('H:i')) }}" required
                       class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
                @if($scheduledOut)
                    <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Your scheduled time out is {{ \Illuminate\Support\Carbon::parse($scheduledOut)->format('g:i A') }}.</p>
                @endif
                @error('requested_time_out') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="reason" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Reason</label>
                <textarea id="reason" name="reason" rows="3" required placeholder="e.g. Not feeling well / fever"
                          class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('reason') }}</textarea>
                @error('reason') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('leave.index') }}" class="btn-app btn-md btn-secondary">Cancel</a>
                <button type="submit" class="btn-app btn-md btn-brand">Submit request</button>
            </div>
        </form>

        <x-form-aside title="What happens next" :facts="$facts">
            <li>HR is notified immediately and approves or denies it — usually the same day.</li>
            <li><b class="text-gray-800 dark:text-slate-100">Approved</b>: clocking out before your scheduled time is <b>not</b> counted as undertime for that day.</li>
            <li><b class="text-gray-800 dark:text-slate-100">Not approved</b>: the minutes before your scheduled time out are deducted at your per-minute rate.</li>
            <li>Still clock out on your phone when you leave — this request does not replace the punch.</li>
        </x-form-aside>
    </div>
</x-app-layout>
