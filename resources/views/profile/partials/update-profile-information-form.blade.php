<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-slate-100">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-slate-300">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="mt-6 space-y-6"
          x-data="{ preview: '{{ $user->profile_photo_url ?? '' }}', remove: false }">
        @csrf
        @method('patch')

        {{-- Profile photo --}}
        <div>
            <x-input-label :value="__('Profile Photo')" />
            <div class="mt-2 flex items-center gap-4">
                {{-- Current photo / preview --}}
                <template x-if="preview && !remove">
                    <img :src="preview" alt="Profile photo"
                         class="h-16 w-16 rounded-full object-cover ring-1 ring-gray-200 dark:ring-slate-700">
                </template>
                {{-- Initial fallback --}}
                <span x-show="!preview || remove"
                      class="grid h-16 w-16 place-items-center rounded-full bg-brand-100 text-xl font-semibold text-brand-700 dark:bg-brand-900 dark:text-brand-200">
                    {{ strtoupper(substr($user->name ?? '?', 0, 1)) }}
                </span>

                <div class="space-y-1.5">
                    <label class="inline-block cursor-pointer rounded-xs bg-gray-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-700">
                        {{ __('Choose photo') }}
                        <input type="file" name="photo" accept="image/png,image/jpeg,image/webp" class="hidden"
                               @change="remove = false; const f = $event.target.files[0]; if (f) preview = URL.createObjectURL(f);">
                    </label>

                    @if ($user->profile_photo_url)
                        <label class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-slate-400">
                            <input type="checkbox" name="remove_photo" value="1" x-model="remove"
                                   class="rounded-xs border-gray-300 dark:border-slate-600 text-brand-600 focus:ring-brand-500">
                            {{ __('Remove current photo') }}
                        </label>
                    @endif

                    <p class="text-xs text-gray-400 dark:text-slate-500">JPG, PNG or WebP · max 2&nbsp;MB.</p>
                </div>
            </div>
            <x-input-error class="mt-2" :messages="$errors->get('photo')" />
        </div>

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-gray-800 dark:text-slate-100">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="underline text-sm text-gray-600 dark:text-slate-300 hover:text-gray-900 rounded-xs focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-500">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-green-600">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600 dark:text-slate-300"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
