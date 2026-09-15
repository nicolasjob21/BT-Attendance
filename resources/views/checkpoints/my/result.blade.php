@php $cp = $checkpoint; $c = $campaign; @endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Checkpoint {{ $cp->reference() }}</h1>
    </x-slot>

    <div class="mx-auto max-w-2xl space-y-4">
        @if(session('status'))
            <div class="rounded-xs border px-4 py-2.5 text-sm {{ $cp->isCompleted() ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/30 dark:text-emerald-200' : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-900/30 dark:text-amber-200' }}">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-xs border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-900/30 dark:text-rose-200">{{ $errors->first() }}</div>
        @endif

        <a href="{{ route('my-checkpoints.index') }}" class="text-sm text-brand-700 hover:underline dark:text-brand-300">← My checkpoints</a>

        <div class="card p-5">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-bold text-gray-900 dark:text-slate-100">{{ $cp->site?->name }}</h2>
                <x-checkpoint-status-badge :checkpoint="$cp" />
            </div>
            <p class="text-sm text-gray-600 dark:text-slate-300">{{ $c->name }} · {{ $c->starts_at?->format('D, M j') }} · window {{ $c->starts_at?->format('g:i A') }}–{{ $c->expires_at?->format('g:i A') }}</p>

            <dl class="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-gray-500 dark:text-slate-400">Instruction</dt><dd class="text-gray-900 dark:text-slate-100">{{ $c->instruction }}</dd></div>
                <div><dt class="text-xs text-gray-500 dark:text-slate-400">Submitted</dt><dd class="text-gray-900 dark:text-slate-100">{{ $cp->submitted_at?->format('g:i:s A') ?? ($cp->last_attempt_at ? 'Last attempt ' . $cp->last_attempt_at->format('g:i A') : 'No submission') }}</dd></div>
                <div><dt class="text-xs text-gray-500 dark:text-slate-400">Result</dt><dd class="text-gray-900 dark:text-slate-100">{{ $cp->verification_label ?? $cp->result_label ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500 dark:text-slate-400">Distance from site</dt><dd class="text-gray-900 dark:text-slate-100">{{ $cp->distance_from_site_meters !== null ? number_format((float) $cp->distance_from_site_meters) . ' m' : '—' }}</dd></div>
            </dl>
            @if($cp->validation_message)<p class="mt-3 rounded-xs bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:bg-slate-800/60 dark:text-slate-200">{{ $cp->validation_message }}</p>@endif
            @if($hasPhoto)
                <button type="button" @click="$dispatch('open-lightbox', '{{ route('checkpoints.photo', $cp) }}')" class="mt-4 block overflow-hidden rounded-xs"><img src="{{ route('checkpoints.photo', $cp) }}" alt="Your checkpoint photo" class="h-40 w-auto object-cover"></button>
            @endif
        </div>

        @if($cp->reviewed_at)
            <div class="card p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">HR decision</h3>
                <p class="mt-1 text-sm text-gray-900 dark:text-slate-100">{{ $cp->status === 'approved_exception' ? 'Exception approved' : 'Exception rejected' }} — {{ $cp->hr_reason_label }}</p>
                @if($cp->hr_note)<p class="text-sm text-gray-700 dark:text-slate-200">“{{ $cp->hr_note }}”</p>@endif
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">— {{ $cp->reviewer?->name }}, {{ $cp->reviewed_at->format('M j, g:i A') }}</p>
            </div>
        @endif

        @if($canExplain)
            <form method="POST" action="{{ route('my-checkpoints.explain', $cp) }}" class="card space-y-3 p-5">
                @csrf
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100">Explain to HR</h3>
                <p class="text-xs text-gray-500 dark:text-slate-400">You didn't complete this checkpoint in time. If you had no internet, a device or GPS problem, or were away on an approved task, tell HR here — a late submission cannot be accepted as a live checkpoint, but HR can approve the exception.</p>
                <select name="issue" class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">
                    <option value="">What happened? (optional)</option>
                    @foreach(\App\Models\Checkpoint::ISSUES as $v => $l)<option value="{{ $v }}" @selected(old('issue', $cp->issue_reported) === $v)>{{ $l }}</option>@endforeach
                </select>
                <textarea name="employee_explanation" rows="3" required maxlength="1000" placeholder="e.g. No mobile signal in the basement until 3 PM." class="w-full rounded-xs border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600">{{ old('employee_explanation', $cp->employee_explanation) }}</textarea>
                @error('employee_explanation') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
                <div class="flex justify-end"><button class="rounded-xs bg-linear-to-r from-brand-600 to-accent-500 px-4 py-2 text-sm font-semibold text-white hover:from-brand-700 hover:to-accent-600">{{ $cp->employee_explanation ? 'Update explanation' : 'Send explanation' }}</button></div>
            </form>
        @elseif($cp->employee_explanation)
            <div class="card p-5 text-sm"><h3 class="font-semibold text-gray-900 dark:text-slate-100">Your explanation</h3><p class="mt-1 text-gray-700 dark:text-slate-200">“{{ $cp->employee_explanation }}”</p></div>
        @endif
    </div>
</x-app-layout>
