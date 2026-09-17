@use('App\Support\RoleMatrix')
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Roles &amp; Permissions</h1>
    </x-slot>
    <x-slot name="back">{{ route('users.index') }}</x-slot>
    <x-slot name="backLabel">Back to User Management</x-slot>

    @php $roleNames = RoleMatrix::ROLES; @endphp

    <div class="page space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-600 dark:text-slate-300 max-w-3xl">
                Tick what each account category can access. Rows marked <span class="badge badge-warn">reserved</span> exist so roles can be prepared for them, but no page uses them yet.
                Super Admin always keeps <em>manage users</em> and <em>manage roles</em> so nobody can be locked out.
            </p>
        </div>

        {{-- Role cards --}}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($roleNames as $name)
                <div class="card p-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ RoleMatrix::ROLE_LABELS[$name] }}</h2>
                        <span class="font-mono text-[10px] text-gray-400 dark:text-slate-500">{{ $name }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-600 dark:text-slate-300">{{ RoleMatrix::ROLE_DESCRIPTIONS[$name] }}</p>
                    <p class="mt-2 text-xs text-gray-500 dark:text-slate-400">
                        <span class="font-semibold text-gray-900 dark:text-slate-100" data-count="{{ $name }}">{{ count($granted[$name] ?? []) }}</span> of {{ count(RoleMatrix::PERMISSIONS) }} permissions ·
                        {{ $roles[$name]?->users()->count() ?? 0 }} account(s)
                    </p>
                </div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('roles.update') }}" x-data="rolesMatrix()" @change="recount()">
            @csrf
            @method('PUT')

            <div class="overflow-hidden card">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                        <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                            <tr>
                                <th class="px-4 py-3 w-[42%]">Permission</th>
                                @foreach($roleNames as $name)
                                    <th class="px-3 py-3 text-center whitespace-nowrap">
                                        {{ RoleMatrix::ROLE_LABELS[$name] }}
                                        <button type="button" @click="toggleColumn('{{ $name }}')" class="ml-1 align-middle text-[10px] font-medium normal-case text-brand-600 hover:underline dark:text-brand-300" title="Toggle all in this column">all</button>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                            @foreach($modules as $module => $perms)
                                <tr class="bg-brand-50/60 dark:bg-brand-500/10">
                                    <td colspan="{{ count($roleNames) + 1 }}" class="px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-brand-800 dark:text-brand-200">{{ $module }}</td>
                                </tr>
                                @foreach($perms as $perm => $meta)
                                    <tr>
                                        <td class="px-4 py-2.5 align-top">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <code class="rounded-xs bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-800 dark:bg-slate-700 dark:text-slate-100">{{ $perm }}</code>
                                                @if(RoleMatrix::isReserved($perm))
                                                    <span class="badge badge-warn">reserved</span>
                                                @endif
                                            </div>
                                            <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">{{ $meta[1] }}</p>
                                        </td>
                                        @foreach($roleNames as $name)
                                            @php $locked = $name === RoleMatrix::SUPERADMIN && in_array($perm, RoleMatrix::LOCKED_SUPERADMIN, true); @endphp
                                            <td class="px-3 py-2.5 text-center align-top">
                                                <label class="inline-flex cursor-pointer items-center justify-center p-1 {{ $locked ? 'cursor-not-allowed opacity-60' : '' }}" title="{{ $locked ? 'Always on for Super Admin' : RoleMatrix::ROLE_LABELS[$name].' · '.$perm }}">
                                                    <input type="checkbox" name="grants[{{ $name }}][{{ $perm }}]" value="1" data-role="{{ $name }}"
                                                           @checked($locked || isset($granted[$name][$perm])) @disabled($locked)
                                                           class="h-4 w-4 rounded-xs border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                                                    @if($locked)<input type="hidden" name="grants[{{ $name }}][{{ $perm }}]" value="1">@endif
                                                </label>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <x-confirm-action :action="route('roles.reset')" title="Reset to defaults?"
                    message="Every role goes back to the permissions in BT-Attendance-Role-Permissions.xlsx. Your changes on this page are discarded." button="Reset to defaults" tone="amber" />
                <div class="flex gap-2">
                    <a href="{{ route('users.index') }}" class="btn-app btn-md btn-secondary">Cancel</a>
                    <button type="submit" class="btn-app btn-md btn-brand">Save permissions</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('rolesMatrix', () => ({
                recount() {
                    @foreach($roleNames as $name)
                        document.querySelector('[data-count="{{ $name }}"]').textContent =
                            document.querySelectorAll('input[type=checkbox][data-role="{{ $name }}"]:checked').length;
                    @endforeach
                },
                toggleColumn(role) {
                    const boxes = [...document.querySelectorAll(`input[type=checkbox][data-role="${role}"]:not(:disabled)`)];
                    const allOn = boxes.every(b => b.checked);
                    boxes.forEach(b => b.checked = !allOn);
                    this.recount();
                },
            }));
        });
    </script>
</x-app-layout>
