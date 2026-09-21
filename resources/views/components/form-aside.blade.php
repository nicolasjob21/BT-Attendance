{{-- Context panel beside a short form: a few figures, then the rules in plain words. --}}
@props(['title' => 'Good to know', 'facts' => []])
<aside class="form-aside space-y-4">
    @if($facts)
        <div class="grid grid-cols-2 gap-3">
            @foreach($facts as $f)
                <x-stat :label="$f['label']" :value="$f['value']" :hint="$f['hint'] ?? null" :tone="$f['tone'] ?? 'neutral'" />
            @endforeach
        </div>
    @endif
    <div class="card p-5">
        <p class="eyebrow">{{ $title }}</p>
        <ul class="mt-3 space-y-2.5 text-sm text-gray-600 dark:text-slate-300">
            {{ $slot }}
        </ul>
    </div>
</aside>
