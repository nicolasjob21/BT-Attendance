<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">{{ __('Profile') }}</h1>
    </x-slot>
    <x-slot name="back">{{ route('dashboard') }}</x-slot>
    <x-slot name="backLabel">Back to Dashboard</x-slot>

    <div class="page space-y-4">
        <div class="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="card p-5 sm:p-6">
                @include('profile.partials.update-profile-information-form')
            </div>

            <div class="space-y-4">
                <div class="card p-5 sm:p-6">
                    @include('profile.partials.update-password-form')
                </div>

                <div class="card border-accent-600/40 p-5 sm:p-6">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
