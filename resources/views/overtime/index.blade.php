<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Overtime Requests</h1>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-4">
        <div class="flex items-center justify-between">
            <p class="text-sm text-gray-500 dark:text-slate-400">
                {{ $canApprove ? 'All overtime requests.' : 'Your overtime requests.' }}
            </p>
            <a href="{{ route('overtime.create') }}" class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-4 py-2 text-sm font-medium text-white hover:from-brand-700 hover:to-accent-600">File Overtime</a>
        </div>

        <div class="overflow-hidden card">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                        <tr>
                            @if($canApprove)<th class="px-4 py-3">Employee</th>@endif
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3 text-center">Hours</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Status</th>
                            @if($canApprove)<th class="px-4 py-3 text-right">Action</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @php $otLabels = ['regular' => 'Regular · 125%', 'rest_day' => 'Rest day · 130%', 'holiday' => 'Holiday · 200%']; @endphp
                        @forelse($requests as $req)
                            <tr>
                                @if($canApprove)<td class="cell-head px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{{ $req->employee?->full_name }}</td>@endif
                                <td data-label="Date" class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-slate-200">{{ $req->ot_date->format('M j, Y') }}</td>
                                <td data-label="Hours" class="px-4 py-3 text-center tabular-nums">{{ rtrim(rtrim(number_format($req->hours, 1), '0'), '.') }}</td>
                                <td data-label="Type" class="px-4 py-3 text-gray-700 dark:text-slate-200">{{ $otLabels[$req->ot_type] ?? $req->ot_type }}</td>
                                <td data-label="Status" class="px-4 py-3"><x-status-badge :status="$req->status" /></td>
                                @if($canApprove)
                                <td data-label="Action" class="px-4 py-3 text-right">
                                    @if($req->status === 'pending')
                                        <div class="flex justify-end gap-2">
                                            <form method="POST" action="{{ route('overtime.approve', $req) }}">@csrf
                                                <button class="rounded-xs bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700">Approve</button>
                                            </form>
                                            <form method="POST" action="{{ route('overtime.deny', $req) }}">@csrf
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
                            <tr><td colspan="{{ $canApprove ? 6 : 4 }}" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">No overtime requests.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $requests->links() }}
    </div>
</x-app-layout>
