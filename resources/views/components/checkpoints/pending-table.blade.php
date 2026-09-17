{{-- TABLE 2: employees who have not (successfully) completed the checkpoint. --}}
@props(['rows', 'campaign' => null])

<div class="overflow-x-auto">
    <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
        <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400 whitespace-nowrap">
            <tr>
                <th class="px-4 py-3">Employee</th>
                <th class="px-4 py-3">Project site</th>
                <th class="px-4 py-3">Deadline</th>
                <th class="px-4 py-3">Result</th>
                <th class="px-4 py-3">Notification</th>
                <th class="px-4 py-3">Last attempt</th>
                <th class="px-4 py-3">GPS</th>
                <th class="px-4 py-3">Explanation / HR note</th>
                <th class="px-4 py-3">HR action</th>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            @forelse($rows as $cp)
                @php $c = $campaign ?? $cp->campaign; @endphp
                <tr class="hover:bg-gray-50/60 dark:hover:bg-slate-800/40">
                    <td class="cell-head px-4 py-3">
                        <div class="font-medium text-gray-900 dark:text-slate-100">{{ $cp->employee?->full_name }}</div>
                        <div class="text-xs text-gray-500 dark:text-slate-400">{{ $cp->employee?->employee_no }} · {{ $cp->reference() }}</div>
                    </td>
                    <td data-label="Project site" class="px-4 py-3 text-gray-700 dark:text-slate-200">{{ $cp->site?->name ?? $c?->site?->name }}</td>
                    <td data-label="Deadline" class="px-4 py-3 whitespace-nowrap tabular-nums text-gray-700 dark:text-slate-200">{{ $c?->expires_at?->format('g:i A') ?? '—' }}</td>
                    <td data-label="Result" class="px-4 py-3">
                        @php $r = $cp->result(); @endphp
                        <span class="badge {{ $r === 'completed' ? 'badge-success' : ($r === 'waiting' ? 'badge-info' : 'badge-danger') }}">{{ $cp->resultLabel() }}</span>
                        <div class="mt-1 text-[11px] text-gray-500 dark:text-slate-400">{{ $cp->status_label }}</div>
                        @if($cp->escalated_at)<span class="mt-0.5 block text-[11px] font-medium text-rose-700 dark:text-rose-300">Escalated</span>@endif
                    </td>
                    <td data-label="Notification" class="px-4 py-3 whitespace-nowrap text-xs text-gray-600 dark:text-slate-300">{{ $cp->notification_status }}</td>
                    <td data-label="Last attempt" class="px-4 py-3 whitespace-nowrap text-xs text-gray-600 dark:text-slate-300">
                        @if($cp->last_attempt_at)
                            {{ $cp->last_attempt_at->format('g:i:s A') }}
                            <span class="block text-[11px] text-gray-400 dark:text-slate-500">{{ $cp->submission_attempts }}× · {{ \App\Models\Checkpoint::RESULTS[$cp->last_attempt_result] ?? $cp->last_attempt_result }}</span>
                        @else
                            <span class="text-gray-400 dark:text-slate-500">None</span>
                        @endif
                    </td>
                    <td data-label="GPS" class="px-4 py-3"><x-checkpoints.gps-badge :checkpoint="$cp" /></td>
                    <td data-label="Explanation / HR note" class="max-w-xs px-4 py-3 text-xs text-gray-600 dark:text-slate-300">
                        @if($cp->employee_explanation)<p class="truncate" title="{{ $cp->employee_explanation }}">“{{ $cp->employee_explanation }}”</p>@endif
                        @if($cp->hr_reason)<p class="truncate font-medium text-gray-800 dark:text-slate-100">{{ $cp->hr_reason_label }}</p>@endif
                        @if($cp->hr_note)<p class="truncate text-gray-500 dark:text-slate-400" title="{{ $cp->hr_note }}">Note: {{ $cp->hr_note }}</p>@endif
                        @if(! $cp->employee_explanation && ! $cp->hr_reason && ! $cp->hr_note)<span class="text-gray-400 dark:text-slate-500">—</span>@endif
                    </td>
                    <td data-label="HR action" class="px-4 py-3 text-xs text-gray-600 dark:text-slate-300">
                        @if($cp->reviewed_at)
                            {{ $cp->status === \App\Models\Checkpoint::APPROVED_EXCEPTION ? 'Approved' : 'Rejected' }}
                            <span class="block text-[11px] text-gray-400 dark:text-slate-500">{{ $cp->reviewer?->name }} · {{ $cp->reviewed_at->format('M j, g:i A') }}</span>
                        @elseif($cp->status === \App\Models\Checkpoint::PENDING_REVIEW)
                            <span class="text-amber-700 dark:text-amber-300">Under review</span>
                        @elseif($c?->isLive())
                            <span class="text-gray-400 dark:text-slate-500">Waiting</span>
                        @else
                            <span class="text-rose-700 dark:text-rose-300">Follow-up needed</span>
                        @endif
                    </td>
                    <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                        <div class="flex items-center justify-end gap-1">
                            @if($cp->employee?->email)
                                <a href="mailto:{{ $cp->employee->email }}?subject={{ rawurlencode('Checkpoint ' . $cp->reference() . ' — ' . ($c?->name ?? '')) }}"
                                   class="btn-app btn-xs btn-secondary" title="Contact employee">Contact</a>
                            @endif
                            @can('review checkpoint exceptions')
                                @if(! $cp->reviewed_at && $cp->status !== \App\Models\Checkpoint::PENDING_REVIEW && ! $c?->isLive())
                                    <form method="POST" action="{{ route('checkpoints.results.follow-up', $cp) }}">
                                        @csrf <input type="hidden" name="action" value="mark_review">
                                        <button class="btn-app btn-xs btn-outline-warn">Mark for review</button>
                                    </form>
                                @endif
                            @endcan
                            @can('view checkpoint results')
                                <a href="{{ route('checkpoints.results.show', $cp) }}" class="btn-app btn-xs btn-outline-brand">View details</a>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-4 py-8 text-center text-gray-400 dark:text-slate-500">Everyone has completed the checkpoint.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
