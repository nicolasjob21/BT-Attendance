<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Overtime Requests</h1>
    </x-slot>

    <div class="page space-y-4">
        @if(session('status'))
            <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200">
                {{ session('status') }}
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500 dark:text-slate-400">
                {{ $canApprove ? 'Overtime requests awaiting your decision, and past decisions.' : 'Your overtime requests. Actual hours are filled in from your clock-out once the day is over.' }}
            </p>
            <a href="{{ route('overtime.create') }}" class="btn-app btn-md btn-brand">Request Overtime</a>
        </div>

        <div class="overflow-hidden card">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                        <tr>
                            @if($canApprove)<th class="px-4 py-3">Employee</th>@endif
                            <th class="px-4 py-3">Date &amp; plan</th>
                            <th class="px-4 py-3">Reason</th>
                            <th class="px-4 py-3 text-center">Hours</th>
                            <th class="px-4 py-3">Status</th>
                            @if($canApprove)<th class="px-4 py-3 text-right">Decision</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($requests as $req)
                            @php
                                $fmt = fn ($h) => $h === null ? null : rtrim(rtrim(number_format((float) $h, 2), '0'), '.');
                                $payable = $req->payableHours();
                            @endphp
                            <tr class="align-top">
                                @if($canApprove)
                                    <td class="cell-head px-4 py-3">
                                        <div class="font-medium text-gray-900 dark:text-slate-100">{{ $req->employee?->full_name }}</div>
                                        <div class="text-xs text-gray-500 dark:text-slate-400">{{ $req->employee?->employee_no }}</div>
                                    </td>
                                @endif
                                <td data-label="Date &amp; plan" class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-medium text-gray-900 dark:text-slate-100">{{ $req->ot_date->format('D, M j, Y') }}</div>
                                    <div class="text-xs text-gray-500 dark:text-slate-400">
                                        @if($req->plannedWindow()){{ $req->plannedWindow() }} · @endif{{ \App\Models\OvertimeRequest::TYPE_LABELS[$req->ot_type] ?? $req->ot_type }}
                                    </div>
                                </td>
                                <td data-label="Reason" class="px-4 py-3">
                                    <p class="max-w-md whitespace-pre-line text-gray-700 dark:text-slate-200">{{ $req->reason ?: '—' }}</p>
                                    @if($req->admin_remarks)
                                        <p class="mt-1 max-w-md text-xs text-gray-500 dark:text-slate-400">
                                            <span class="font-medium">{{ $req->approver?->full_name ?? 'Approver' }}:</span> “{{ $req->admin_remarks }}”
                                        </p>
                                    @endif
                                </td>
                                <td data-label="Hours" class="px-4 py-3 text-center tabular-nums">
                                    @if($req->requested_hours !== null)
                                        <div class="text-gray-900 dark:text-slate-100"><span class="text-xs text-gray-400 dark:text-slate-500">planned</span> {{ $fmt($req->requested_hours) }}h</div>
                                    @endif
                                    @if($req->hours !== null)
                                        <div class="text-gray-900 dark:text-slate-100">
                                            <span class="text-xs text-gray-400 dark:text-slate-500">actual</span> {{ $fmt($req->hours) }}h
                                            @if($payable !== null && (float) $payable < (float) $req->hours)
                                                <span class="text-xs text-accent-600 dark:text-accent-300" title="Paid hours are capped at the approved plan">(paid {{ $fmt($payable) }}h)</span>
                                            @endif
                                        </div>
                                    @elseif($req->status === 'approved')
                                        <div class="text-xs text-gray-400 dark:text-slate-500">{{ $req->ot_date->isFuture() ? 'upcoming' : 'awaiting clock-out' }}</div>
                                    @endif
                                    @if($req->status === 'approved')
                                        <div class="mt-0.5 text-[10px] text-gray-400 dark:text-slate-500" title="Overtime is paid one cutoff later">paid in {{ $req->payoutCutoffLabel() }}</div>
                                    @endif
                                </td>
                                <td data-label="Status" class="px-4 py-3">
                                    <x-status-badge :status="$req->status" />
                                    @if($req->status !== 'pending')
                                        <div class="mt-1 text-[11px] text-gray-400 dark:text-slate-500">by {{ $req->approver?->full_name ?? '—' }}@if($req->approved_at) · {{ $req->approved_at->format('M j') }}@endif</div>
                                    @endif
                                </td>
                                @if($canApprove)
                                <td data-label="Decision" class="px-4 py-3">
                                    @if($req->status === 'pending')
                                        <form method="POST" action="{{ route('overtime.approve', $req) }}" class="ml-auto flex w-full max-w-xs flex-col gap-1.5 sm:items-end">
                                            @csrf
                                            <input type="text" name="remarks" maxlength="500" placeholder="Remarks (optional)"
                                                   class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-xs focus:border-brand-500 focus:ring-brand-500">
                                            <div class="flex gap-1.5">
                                                <button formaction="{{ route('overtime.approve', $req) }}"
                                                        class="btn-app btn-xs btn-success">Approve</button>
                                                <button formaction="{{ route('overtime.deny', $req) }}"
                                                        class="btn-app btn-xs btn-outline-danger">Deny</button>
                                            </div>
                                        </form>
                                    @else
                                        <span class="text-xs text-gray-400 dark:text-slate-500">Decided</span>
                                    @endif
                                </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canApprove ? 6 : 4 }}" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">No overtime requests yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $requests->links() }}
    </div>
</x-app-layout>
