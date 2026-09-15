<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Check Point · History</h1>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <select name="status" onchange="this.form.submit()" class="rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                    <option value="">Completed &amp; cancelled</option>
                    @foreach(\App\Models\CheckpointCampaign::STATUSES as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="site" onchange="this.form.submit()" class="rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                    <option value="">All sites</option>
                    @foreach($sites as $s)<option value="{{ $s->id }}" @selected($siteId === $s->id)>{{ $s->name }}</option>@endforeach
                </select>
                <input type="date" name="from" value="{{ $from }}" onchange="this.form.submit()" aria-label="From date" class="rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                <span class="text-xs text-gray-400">to</span>
                <input type="date" name="to" value="{{ $to }}" onchange="this.form.submit()" aria-label="To date" class="rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                @if($status || $siteId || $from || $to)<a href="{{ route('checkpoints.history') }}" class="text-sm text-gray-500 hover:underline dark:text-slate-400">Clear</a>@endif
            </form>
            <a href="{{ route('checkpoints.index') }}" class="text-sm text-brand-700 hover:underline dark:text-brand-300">← Back to Check Point</a>
        </div>

        <div class="card overflow-hidden">
            <x-checkpoints.campaign-table :campaigns="$campaigns" empty="No checkpoints in history." />
        </div>

        {{ $campaigns->links() }}
    </div>
</x-app-layout>
