<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Leave Requests</h1>
    </x-slot>

    <div class="page space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-gray-500 dark:text-slate-400">
                {{ $canApprove ? 'Every employee\'s leave requests — approve or deny the pending ones.' : 'Your leave requests and their status.' }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('leave.early.create') }}" class="btn-app btn-md btn-secondary">Go home early / Sick</a>
                <a href="{{ route('leave.create') }}" class="btn-app btn-md btn-brand">File Leave</a>
            </div>
        </div>

        <div class="stat-strip">
            <x-stat :label="$canApprove ? 'Awaiting approval' : 'Pending'" :value="$stats['pending']" :hint="$stats['pending'] ? 'needs a decision' : 'nothing waiting'" :tone="$stats['pending'] ? 'warn' : 'success'" />
            <x-stat label="Approved this month" :value="$stats['approved_month']" :hint="now()->format('F')" tone="brand" />
            <x-stat label="Days taken this year" :value="rtrim(rtrim(number_format($stats['days_year'], 1), '0'), '.')" :hint="'approved leave in '.now()->year" />
            <x-stat label="Denied this year" :value="$stats['denied_year']" :tone="$stats['denied_year'] ? 'danger' : 'muted'" />
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
                                        <span class="badge badge-warn ml-1">Early leave</span>
                                    @endif
                                </td>
                                <td data-label="Dates" class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-slate-200">
                                    @if($req->isHalfDay())
                                        {{ $req->date_from->format('M j, Y') }}
                                        <span class="badge badge-info ml-1">{{ $req->day_portion === 'half_am' ? 'AM' : 'PM' }}</span>
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
                                                <button class="btn-app btn-xs btn-success">Approve</button>
                                            </form>
                                            <form method="POST" action="{{ route('leave.deny', $req) }}">@csrf
                                                <button class="btn-app btn-xs btn-danger">Deny</button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-xs text-gray-400 dark:text-slate-500">by {{ $req->approver?->full_name ?? '—' }}</span>
                                    @endif
                                </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canApprove ? 7 : 5 }}" class="p-0"><x-empty-state icon="calendar" title="No leave requests" :hint="$canApprove ? 'Requests employees file will show up here for approval.' : 'File vacation, sick or early-leave requests and track them here.'" :href="route('leave.create')" action="File leave" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $requests->links() }}
    </div>
</x-app-layout>
