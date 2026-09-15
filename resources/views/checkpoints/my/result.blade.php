@php $cp = $checkpoint; @endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Checkpoint {{ $cp->reference() }}</h1>
    </x-slot>

    <div class="mx-auto max-w-2xl space-y-4">
        @if(session('status'))
            <div class="rounded-xs border px-4 py-2.5 text-sm {{ $cp->verification_status === 'verified' ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200' : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-900/30 dark:text-amber-200' }}">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-xs border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-900/30 dark:text-rose-200">{{ $errors->first() }}</div>
        @endif

        <a href="{{ route('my-checkpoints.index') }}" class="text-sm text-brand-700 hover:underline dark:text-brand-300">← My checkpoints</a>

        <div class="card p-5">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-bold text-gray-900 dark:text-slate-100">{{ $cp->site?->name }}</h2>
                <x-checkpoint-status-badge :status="$cp->verification_status" />
            </div>
            <p class="text-sm text-gray-600 dark:text-slate-300">{{ $cp->scheduled_at->format('D, M j, Y') }} · opened {{ $cp->opened_at?->format('g:i A') ?? '—' }} · window closed {{ $cp->expires_at?->format('g:i A') ?? '—' }}</p>

            <dl class="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-gray-500 dark:text-slate-400">Submitted</dt><dd class="text-gray-900 dark:text-slate-100">{{ $cp->submitted_at?->format('g:i:s A') ?? 'No submission' }}</dd></div>
                <div><dt class="text-xs text-gray-500 dark:text-slate-400">Result</dt><dd class="text-gray-900 dark:text-slate-100">{{ $cp->result_label ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500 dark:text-slate-400">Distance from site</dt><dd class="text-gray-900 dark:text-slate-100">{{ $cp->distance_from_site_meters !== null ? number_format((float) $cp->distance_from_site_meters) . ' m' : '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500 dark:text-slate-400">Photo instruction</dt><dd class="text-gray-900 dark:text-slate-100">{{ $cp->photo_instruction }}</dd></div>
            </dl>
            @if($cp->validation_message)
                <p class="mt-3 rounded-xs bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:bg-slate-800/60 dark:text-slate-200">{{ $cp->validation_message }}</p>
            @endif

            @if($hasPhoto)
                <button type="button" @click="$dispatch('open-lightbox', '{{ route('checkpoints.photo', $cp) }}')" class="mt-4 block overflow-hidden rounded-xs">
                    <img src="{{ route('checkpoints.photo', $cp) }}" alt="Your checkpoint photo" class="h-40 w-auto object-cover">
                </button>
            @endif
        </div>

        @if($cp->review_status === 'reviewed')
            <div class="card p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">HR review</h3>
                <p class="mt-1 text-sm text-gray-900 dark:text-slate-100">{{ $cp->review_result_label }}</p>
                @if($cp->review_remarks)<p class="text-sm text-gray-700 dark:text-slate-200">“{{ $cp->review_remarks }}”</p>@endif
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">— {{ $cp->reviewer?->name }}, {{ $cp->reviewed_at?->format('M j, g:i A') }}</p>
            </div>
        @endif

        @if($canExplain)
            <form method="POST" action="{{ route('my-checkpoints.explain', $cp) }}" class="card space-y-3 p-5">
                @csrf
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Explain to HR</h3>
                <p class="text-xs text-gray-500 dark:text-slate-400">This checkpoint is under review. If you were on an approved errand, had a GPS or signal problem, or could not respond in time, say so here.</p>
                <textarea name="employee_explanation" rows="3" required maxlength="1000" placeholder="e.g. Sent by the foreman to the hardware store at 2 PM; back on site by 3:30."
                          class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">{{ old('employee_explanation', $cp->employee_explanation) }}</textarea>
                @error('employee_explanation') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
                <div class="flex justify-end">
                    <button class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-4 py-2 text-sm font-semibold text-white hover:from-brand-700 hover:to-accent-600">{{ $cp->employee_explanation ? 'Update explanation' : 'Send explanation' }}</button>
                </div>
            </form>
        @elseif($cp->employee_explanation)
            <div class="card p-5 text-sm">
                <h3 class="font-semibold text-gray-900 dark:text-slate-100">Your explanation</h3>
                <p class="mt-1 text-gray-700 dark:text-slate-200">“{{ $cp->employee_explanation }}”</p>
            </div>
        @endif
    </div>
</x-app-layout>
