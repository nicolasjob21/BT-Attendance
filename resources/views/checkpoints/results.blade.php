<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Check Point · All responses</h1>
    </x-slot>
    <x-slot name="back">{{ route('checkpoints.index') }}</x-slot>
    <x-slot name="backLabel">Back to Check Point</x-slot>

    <div class="page space-y-4">

        <form method="GET" class="card flex flex-wrap items-center gap-2 p-3">
            <select name="campaign" class="min-w-[180px] flex-1 rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                <option value="">All checkpoints</option>
                @foreach($campaigns as $c)<option value="{{ $c->id }}" @selected($filters['campaign'] === $c->id)>{{ $c->name }}</option>@endforeach
            </select>
            <select name="employee" class="min-w-[180px] flex-1 rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                <option value="">All employees</option>
                @foreach($employees as $e)<option value="{{ $e->id }}" @selected($filters['employee'] === $e->id)>{{ $e->full_name }}</option>@endforeach
            </select>
            <select name="status" class="min-w-[160px] flex-1 rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                <option value="">All statuses</option>
                @foreach(\App\Models\Checkpoint::STATUSES as $v => $l)<option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>@endforeach
            </select>
            <select name="follow" class="min-w-[180px] flex-1 rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                <option value="">Any follow-up state</option>
                <option value="open" @selected($filters['follow'] === 'open')>Follow-up open</option>
                <option value="reviewed" @selected($filters['follow'] === 'reviewed')>Reviewed</option>
                <option value="escalated" @selected($filters['follow'] === 'escalated')>Escalated</option>
            </select>
            <input type="date" name="from" value="{{ $filters['from'] }}" class="min-w-[150px] rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
            <input type="date" name="to" value="{{ $filters['to'] }}" class="min-w-[150px] rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
            <div class="flex shrink-0 gap-2">
                <button class="btn-app btn-md btn-dark">Filter</button>
                @if(array_filter($filters))
                    <a href="{{ route('checkpoints.results.index') }}" class="btn-app btn-md btn-secondary">Clear</a>
                @endif
            </div>
        </form>

        <p class="text-xs text-gray-500 dark:text-slate-400">{{ $checkpoints->total() }} response(s)</p>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 whitespace-nowrap dark:bg-slate-800/60 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Employee</th>
                            <th class="px-4 py-3">Checkpoint</th>
                            <th class="px-4 py-3">Window</th>
                            <th class="px-4 py-3">Submitted</th>
                            <th class="px-4 py-3">GPS</th>
                            <th class="px-4 py-3 text-right">Distance</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Follow-up</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($checkpoints as $cp)
                            <tr class="hover:bg-gray-50/60 dark:hover:bg-slate-800/40">
                                <td class="cell-head px-4 py-3">
                                    <div class="font-medium text-gray-900 dark:text-slate-100">{{ $cp->employee?->full_name }}</div>
                                    <div class="text-xs text-gray-500 dark:text-slate-400">{{ $cp->employee?->employee_no }} · {{ $cp->reference() }}</div>
                                </td>
                                <td data-label="Checkpoint" class="px-4 py-3"><a href="{{ route('checkpoints.show', $cp->campaign_id) }}" class="hover:underline">{{ $cp->campaign?->name }}</a><span class="block text-xs text-gray-500 dark:text-slate-400">{{ $cp->site?->name }}</span></td>
                                <td data-label="Window" class="px-4 py-3 whitespace-nowrap tabular-nums text-gray-700 dark:text-slate-200">{{ $cp->campaign?->starts_at?->format('M j, g:i') }}–{{ $cp->campaign?->expires_at?->format('g:i A') }}</td>
                                <td data-label="Submitted" class="px-4 py-3 whitespace-nowrap tabular-nums">{{ $cp->submitted_at?->format('g:i:s A') ?? '—' }}</td>
                                <td data-label="GPS" class="px-4 py-3"><x-checkpoints.gps-badge :checkpoint="$cp" /></td>
                                <td data-label="Distance" class="px-4 py-3 text-right tabular-nums">{{ $cp->distance_from_site_meters !== null ? number_format((float) $cp->distance_from_site_meters) . ' m' : '—' }}</td>
                                <td data-label="Status" class="px-4 py-3"><x-checkpoint-status-badge :checkpoint="$cp" /></td>
                                <td data-label="Follow-up" class="px-4 py-3 text-xs text-gray-600 dark:text-slate-300">
                                    @if($cp->reviewed_at) {{ $cp->hr_reason_label }} <span class="block text-[11px] text-gray-400">{{ $cp->reviewer?->name }}</span>
                                    @elseif($cp->escalated_at) <span class="text-rose-700 dark:text-rose-300">Escalated</span>
                                    @elseif($cp->isNonCompliant()) <span class="text-amber-700 dark:text-amber-300">Open</span>
                                    @else — @endif
                                </td>
                                <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex justify-end"><a href="{{ route('checkpoints.results.show', $cp) }}" class="btn-app btn-xs btn-secondary">Details</a></div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-8 text-center text-gray-400 dark:text-slate-500">No responses match your filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $checkpoints->links() }}
    </div>
</x-app-layout>
