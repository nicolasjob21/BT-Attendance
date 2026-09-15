<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Check Point · Results</h1>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-4">
        @if(session('status'))
            <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200">{{ session('status') }}</div>
        @endif

        <form method="GET" class="card grid gap-2 p-3 sm:grid-cols-3 lg:grid-cols-7">
            <select name="campaign" class="rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                <option value="">All campaigns</option>
                @foreach($campaigns as $c)<option value="{{ $c->id }}" @selected($filters['campaign'] === $c->id)>{{ $c->name }}</option>@endforeach
            </select>
            <select name="employee" class="rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                <option value="">All employees</option>
                @foreach($employees as $e)<option value="{{ $e->id }}" @selected($filters['employee'] === $e->id)>{{ $e->full_name }}</option>@endforeach
            </select>
            <select name="status" class="rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                <option value="">All statuses</option>
                @foreach(\App\Models\Checkpoint::STATUSES as $v => $l)@if($v !== 'scheduled')<option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>@endif @endforeach
            </select>
            <select name="review" class="rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                <option value="">Any review state</option>
                <option value="pending" @selected($filters['review'] === 'pending')>Needs review</option>
                <option value="reviewed" @selected($filters['review'] === 'reviewed')>Reviewed</option>
            </select>
            <input type="date" name="from" value="{{ $filters['from'] }}" class="rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
            <input type="date" name="to" value="{{ $filters['to'] }}" class="rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
            <div class="flex gap-2">
                <button class="w-full rounded-xs bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Filter</button>
                <a href="{{ route('checkpoints.results.index') }}" class="rounded-xs border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300">Clear</a>
            </div>
        </form>

        <p class="text-xs text-gray-500 dark:text-slate-400">{{ $checkpoints->total() }} checkpoint(s) · <a href="{{ route('checkpoints.index') }}" class="text-brand-700 hover:underline dark:text-brand-300">Back to Check Point</a></p>

        <div class="card overflow-hidden">
            <x-checkpoints.activity-table :checkpoints="$checkpoints" empty="No checkpoints match your filters." />
        </div>

        {{ $checkpoints->links() }}
    </div>
</x-app-layout>
