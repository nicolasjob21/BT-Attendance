{{-- "12:05 AM⁺¹": marks a time that falls on a later calendar day than the one the row is for. --}}
@props(['from', 'to'])
@php
    $days = $from && $to ? (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) : 0;
@endphp
@if($days > 0)
    <sup {{ $attributes->merge(['class' => 'ml-0.5 text-[10px] font-semibold text-brand-600 dark:text-brand-300']) }}
         title="Next day — {{ $to->format('D, M j') }}">+{{ $days }}</sup>
@endif
