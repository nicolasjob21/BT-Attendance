<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Leave Requests</h1>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-4">
        <div class="flex items-center justify-between">
            <p class="text-sm text-gray-500 dark:text-slate-400">
                {{ $canApprove ? 'All employee leave requests.' : 'Your leave requests.' }}
            </p>
            <div class="flex items-center gap-2">
                <a href="{{ route('leave.early.create') }}" class="rounded-xs border border-amber-300 px-4 py-2 text-sm font-medium text-amber-700 hover:bg-amber-50 dark:border-amber-800 dark:text-amber-300 dark:hover:bg-amber-900/30">Go home early / Sick</a>
                <a href="{{ route('leave.create') }}" class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-4 py-2 text-sm font-medium text-white hover:from-brand-700 hover:to-accent-600">File Leave</a>
            </div>
        </div>

        <div class="overflow-hidden card">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                        <tr>
                            @if($canApprove)<th class="px-4 py-3">Employee</th>@endif
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Dates</th>
                            <th class="px-4 py-3 text-center">Days</th>
                            <th class="px-4 py-3">Reason</th>
                            <th class="px-4 py-3">Status</th>
                            @if($canApprove)<th class="px-4 py-3 text-right">Action</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($requests as $req)
                            <tr>
                                @if($canApprove)<td class="cell-head px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{{ $req->employee?->full_name }}</td>@endif
                                <td data-label="Type" class="px-4 py-3 text-gray-700 dark:text-slate-200">
                                    {{ $req->leaveType?->name ?? ($req->isHalfDay() ? 'Half day' : '—') }}
                                    @if($req->is_early_leave)
                                        <span class="ml-1 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Early leave</span>
                                    @endif
                                </td>
                                <td data-label="Dates" class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-slate-200">
                                    @if($req->isHalfDay())
                                        {{ $req->date_from->format('M j, Y') }}
                                        <span class="ml-1 inline-flex items-center rounded-full bg-brand-100 px-2 py-0.5 text-[11px] font-medium text-brand-700 dark:bg-brand-900/40 dark:text-brand-200">{{ $req->day_portion === 'half_am' ? 'AM' : 'PM' }}</span>
                                        @if($req->is_early_leave && $req->requested_time_out)
                                            <span class="block text-[11px] text-gray-400 dark:text-slate-500">out by {{ \Illuminate\Support\Carbon::parse($req->requested_time_out)->format('g:i A') }}</span>
                                        @endif
                                    @else
                                        {{ $req->date_from->format('M j') }} – {{ $req->date_to->format('M j, Y') }}
                                    @endif
                                </td>
                                <td data-label="Days" class="px-4 py-3 text-center tabular-nums">{{ rtrim(rtrim(number_format($req->days, 1), '0'), '.') }}</td>
                                <td data-label="Reason" class="px-4 py-3 max-w-xs truncate text-gray-500 dark:text-slate-400">{{ $req->reason ?: '—' }}</td>
                                <td data-label="Status" class="px-4 py-3"><x-status-badge :status="$req->status" /></td>
                                @if($canApprove)
                                <td data-label="Action" class="px-4 py-3 text-right">
                                    @if($req->status === 'pending')
                                        <div class="flex justify-end gap-2">
                                            <form method="POST" action="{{ route('leave.approve', $req) }}">@csrf
                                                <button class="rounded-xs bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700">Approve</button>
                                            </form>
                                            <form method="POST" action="{{ route('leave.deny', $req) }}">@csrf
                                                <button class="rounded-xs bg-rose-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-rose-700">Deny</button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-xs text-gray-400 dark:text-slate-500">by {{ $req->approver?->full_name ?? '—' }}</span>
                                    @endif
                                </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canApprove ? 7 : 5 }}" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">No leave requests.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $requests->links() }}
    </div>
</x-app-layout>
