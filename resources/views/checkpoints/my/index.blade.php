<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">My Checkpoints</h1>
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-5">
        @if(session('status'))
            <div class="rounded-xs border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200">{{ session('status') }}</div>
        @endif

        {{-- Open now --}}
        @if($open->isNotEmpty())
            @foreach($open as $cp)
                <a href="{{ route('my-checkpoints.show', $cp) }}"
                   class="block rounded-xs border-2 border-accent-500 bg-accent-50 p-4 shadow-xs transition hover:bg-accent-100 dark:bg-accent-900/20 dark:hover:bg-accent-900/30"
                   x-data="{ left: 0, tick() { this.left = Math.max(0, Math.floor((new Date(@js($cp->expires_at->toIso8601String())) - Date.now()) / 1000)); } }" x-init="tick(); setInterval(() => tick(), 1000)">
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-accent-500 text-white animate-pulse">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/></svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-accent-900 dark:text-accent-100">Presence verification required</p>
                            <p class="text-sm text-accent-800/80 dark:text-accent-200/80">{{ $cp->site?->name }} · expires {{ $cp->expires_at->format('g:i A') }}</p>
                        </div>
                        <div class="text-right">
                            <p class="font-display text-2xl font-extrabold tabular-nums text-accent-700 dark:text-accent-200" x-text="Math.floor(left/60) + ':' + String(left%60).padStart(2,'0')"></p>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-accent-700/80 dark:text-accent-200/70">Open checkpoint →</p>
                        </div>
                    </div>
                </a>
            @endforeach
        @else
            <div class="card p-5 text-sm text-gray-600 dark:text-slate-300">
                <p class="font-medium text-gray-900 dark:text-slate-100">No checkpoint is open right now.</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">When HR runs a presence check at your project site, you'll get a notification and this page will show a countdown. Have your phone's location and camera ready.</p>
            </div>
        @endif

        {{-- History --}}
        <div class="card overflow-hidden">
            <div class="border-b border-gray-200 px-4 py-3 text-sm font-semibold text-gray-900 dark:border-slate-700 dark:text-slate-100">My checkpoint history</div>
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 whitespace-nowrap dark:bg-slate-800/60 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Checkpoint</th>
                            <th class="px-4 py-3">Project site</th>
                            <th class="px-4 py-3">Submitted</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Review</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($recent as $cp)
                            <tr>
                                <td class="cell-head px-4 py-3">
                                    <div class="font-medium text-gray-900 dark:text-slate-100">{{ $cp->scheduled_at->format('D, M j · g:i A') }}</div>
                                    <div class="text-xs text-gray-500 dark:text-slate-400">{{ $cp->reference() }}</div>
                                </td>
                                <td data-label="Project site" class="px-4 py-3">{{ $cp->site?->name }}</td>
                                <td data-label="Submitted" class="px-4 py-3 tabular-nums">{{ $cp->submitted_at?->format('g:i A') ?? '—' }}</td>
                                <td data-label="Status" class="px-4 py-3"><x-checkpoint-status-badge :status="$cp->verification_status" /></td>
                                <td data-label="Review" class="px-4 py-3 text-xs">
                                    @if($cp->review_status === 'reviewed') {{ $cp->review_result_label }}
                                    @elseif($cp->review_status === 'pending') <span class="text-amber-700 dark:text-amber-300">Under review</span>
                                    @else — @endif
                                </td>
                                <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex justify-end">
                                        <a href="{{ route('my-checkpoints.show', $cp) }}" class="rounded-xs border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700/60">
                                            {{ $cp->isException() && $cp->review_status !== 'reviewed' && ! $cp->employee_explanation ? 'Explain' : 'Details' }}
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400 dark:text-slate-500">No checkpoints yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ $recent->links() }}
    </div>
</x-app-layout>
