<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Add Attendance Location</h1>
    </x-slot>
    <x-slot name="back">{{ route('sites.index') }}</x-slot>
    <x-slot name="backLabel">Back to Locations</x-slot>

    <div class="page-form">
        <form method="POST" action="{{ route('sites.store') }}" class="space-y-6 card p-6">
            @csrf
            @include('sites._form')

            <div class="flex justify-end gap-2 border-t border-gray-100 dark:border-slate-700 pt-5">
                <a href="{{ route('sites.index') }}" class="btn-app btn-md btn-secondary">Cancel</a>
                <button type="submit" class="btn-app btn-md btn-brand">Save location</button>
            </div>
        </form>
    </div>
</x-app-layout>
