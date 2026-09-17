<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">User Management</h1>
    </x-slot>

    @php
        $badge = [
            'online'   => 'badge-success',
            'offline'  => 'badge-neutral',
            'disabled' => 'badge-danger',
            'deleted'  => 'badge-muted line-through',
        ];
        $dot = [
            'online'   => 'dot animate-pulse',
            'offline'  => 'dot',
            'disabled' => 'dot',
            'deleted'  => 'dot',
        ];
    @endphp

    <div class="page space-y-4"
         x-data="presence({{ $users->pluck('id')->toJson() }}, '{{ route('users.presence') }}', {{ json_encode($badge) }}, {{ json_encode($dot) }})">

        @if($errors->has('account'))
            <div class="rounded-xs border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-900/30 dark:text-rose-200">{{ $errors->first('account') }}</div>
        @endif

        {{-- Summary --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach([
                ['label' => 'Accounts', 'value' => $counts['total'], 'href' => route('users.index'), 'tone' => 'text-gray-900 dark:text-slate-100'],
                ['label' => 'Online now', 'value' => $counts['online'], 'href' => route('users.index', ['status' => 'online']), 'tone' => 'text-emerald-600 dark:text-emerald-300'],
                ['label' => 'Disabled', 'value' => $counts['disabled'], 'href' => route('users.index', ['status' => 'disabled']), 'tone' => 'text-rose-600 dark:text-rose-300'],
                ['label' => 'Deleted', 'value' => $counts['deleted'], 'href' => route('users.index', ['status' => 'deleted']), 'tone' => 'text-gray-500 dark:text-slate-400'],
            ] as $tile)
                <a href="{{ $tile['href'] }}" class="card p-4 hover:border-brand-400 dark:hover:border-brand-500/60">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-slate-400">{{ $tile['label'] }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums {{ $tile['tone'] }}">{{ $tile['value'] }}</p>
                </a>
            @endforeach
        </div>

        {{-- Toolbar --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
            <form method="GET" action="{{ route('users.index') }}" class="grid grid-cols-2 gap-2 sm:flex sm:flex-1 sm:flex-wrap sm:items-center">
                <input type="text" name="search" value="{{ $search }}" placeholder="Search name, username, or email…"
                       class="col-span-2 w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500 sm:w-auto sm:min-w-[220px] sm:flex-1">
                <select name="role" class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500 sm:w-auto">
                    <option value="">All roles</option>
                    @foreach($roles as $r => $label)
                        <option value="{{ $r }}" @selected($role === $r)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="status" class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500 sm:w-auto">
                    <option value="">All statuses</option>
                    <option value="online" @selected($status === 'online')>Online</option>
                    <option value="offline" @selected($status === 'offline')>Offline</option>
                    <option value="disabled" @selected($status === 'disabled')>Disabled</option>
                    <option value="deleted" @selected($status === 'deleted')>Deleted</option>
                </select>
                <button class="col-span-2 w-full btn-app btn-md btn-dark sm:w-auto">Filter</button>
                @if($search || $role || $status)
                    <a href="{{ route('users.index') }}" class="col-span-2 text-sm text-gray-500 dark:text-slate-400 hover:underline">Clear</a>
                @endif
            </form>

            <div class="grid grid-cols-2 gap-2 sm:flex">
                @can('manage roles')
                    <a href="{{ route('roles.index') }}" class="btn-app btn-md btn-secondary">Roles &amp; Permissions</a>
                @endcan
                @can('manage employees')
                    <a href="{{ route('employees.create') }}" class="btn-app btn-md btn-brand">+ Add Employee</a>
                @endcan
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-slate-400">
            {{ $users->total() }} account(s) · Online = active in the last {{ intdiv($onlineWithin, 60) }} minutes · refreshes every 30 s
        </p>

        <div class="overflow-hidden card">
            <div class="overflow-x-auto">
                <table class="table-stack min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-800/60 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400 whitespace-nowrap">
                        <tr>
                            <th class="px-4 py-3">User</th>
                            <th class="px-4 py-3">Username</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Role</th>
                            <th class="px-4 py-3">Last sign-in</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        @forelse($users as $u)
                            @php $st = $u->presenceStatus(); @endphp
                            <tr id="user-row-{{ $u->id }}" @class(['opacity-60' => $st === 'deleted'])>
                                <td class="cell-head px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        @if($u->profile_photo_url)
                                            <img src="{{ $u->profile_photo_url }}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover ring-1 ring-gray-200 dark:ring-slate-700">
                                        @else
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700 dark:bg-brand-900 dark:text-brand-200">{{ strtoupper(mb_substr($u->name, 0, 1)) }}</span>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="font-medium text-gray-900 dark:text-slate-100 truncate">
                                                {{ $u->name }}
                                                @if($u->is(auth()->user())) <span class="ml-1 text-[10px] font-semibold uppercase tracking-wider text-brand-600 dark:text-brand-300">you</span> @endif
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-slate-400">
                                                @if($u->employee) {{ $u->employee->employee_no }} @else <span class="text-gray-400 dark:text-slate-500">No employee profile</span> @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Username" class="px-4 py-3 font-mono text-xs text-gray-800 dark:text-slate-200 whitespace-nowrap">{{ $u->username }}</td>
                                <td data-label="Email" class="px-4 py-3 text-xs text-gray-600 dark:text-slate-300 break-all">{{ $u->email }}</td>
                                <td data-label="Role" class="px-4 py-3 text-gray-700 dark:text-slate-200">{{ $u->roles->map(fn ($r) => \App\Support\RoleMatrix::ROLE_LABELS[$r->name] ?? ucfirst($r->name))->implode(', ') ?: '—' }}</td>
                                <td data-label="Last sign-in" class="px-4 py-3 text-xs text-gray-600 dark:text-slate-300 whitespace-nowrap">
                                    @if($u->last_login_at)
                                        <span title="{{ $u->last_login_at->format('M j, Y g:i A') }}{{ $u->last_login_ip ? ' · '.$u->last_login_ip : '' }}">{{ $u->last_login_at->diffForHumans() }}</span>
                                    @else
                                        <span class="text-gray-400 dark:text-slate-500">Never</span>
                                    @endif
                                </td>
                                <td data-label="Status" class="px-4 py-3 whitespace-nowrap">
                                    <span data-presence="{{ $u->id }}"
                                          class="badge {{ $badge[$st] }}"
                                          title="{{ $u->last_seen_at ? 'Last seen '.$u->last_seen_at->diffForHumans() : 'Never signed in' }}">
                                        <i class="{{ $dot[$st] }}" aria-hidden="true"></i>
                                        <span data-presence-label>{{ $st }}</span>
                                    </span>
                                </td>
                                <td data-label="Actions" class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex justify-end">
                                        <div class="row-actions">
                                        @if($st === 'deleted')
                                            <x-confirm-action :action="route('users.restore', $u->id)" title="Restore this account?"
                                                message="{{ $u->name }} will be able to sign in again with their existing password." button="Restore" tone="emerald" class="is-success" />
                                        @else
                                            <a href="{{ route('users.edit', $u) }}" class="is-primary">Edit</a>
                                            @unless($u->is(auth()->user()))
                                                @if($u->isDisabled())
                                                    <x-confirm-action :action="route('users.disabled', $u)" method="PATCH" title="Enable this account?"
                                                        message="{{ $u->name }} will be able to sign in again." button="Enable" tone="emerald" class="is-success" />
                                                @else
                                                    <x-confirm-action :action="route('users.disabled', $u)" method="PATCH" title="Disable this account?"
                                                        message="{{ $u->name }} will be signed out now and blocked from signing in until re-enabled. Nothing is deleted." button="Disable" tone="amber" class="is-warn" />
                                                @endif
                                                <button type="button"
                                                        @click="askDelete({{ $u->id }}, @js($u->name), @js($u->username), '{{ route('users.destroy', $u) }}')"
                                                        class="is-danger">Delete</button>
                                            @endunless
                                        @endif
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400 dark:text-slate-500">No accounts match your filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $users->links() }}

        {{-- Delete: password-confirmed, like BT-Inventory --}}
        <template x-teleport="body">
            <div x-show="del.open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center" @keydown.escape.window="del.open = false">
                <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="del.open = false" x-transition.opacity></div>
                <form method="POST" :action="del.url" x-show="del.open" x-transition
                      class="relative w-full max-w-md rounded-xs border border-gray-200 bg-white p-5 shadow-2xl dark:border-hair dark:bg-surface">
                    @csrf
                    @method('DELETE')
                    <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">Delete this account?</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">
                        <span class="font-medium text-gray-900 dark:text-slate-100" x-text="del.name"></span>
                        (<span class="font-mono text-xs" x-text="del.username"></span>) will be signed out and can no longer sign in.
                        Attendance, payroll and checkpoint history are kept, and the account can be restored from the <em>Deleted</em> filter.
                    </p>
                    <label for="delete-password" class="mt-4 block text-sm font-medium text-gray-700 dark:text-slate-200">Enter your password to confirm</label>
                    <input id="delete-password" type="password" name="password" required autocomplete="current-password"
                           class="mt-1 w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-rose-500 focus:ring-rose-500">
                    @error('password') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" @click="del.open = false" class="btn-app btn-md btn-secondary">Cancel</button>
                        <button type="submit" class="btn-app btn-md btn-danger">Delete account</button>
                    </div>
                </form>
            </div>
        </template>
    </div>

    <script>
        // Live Status column: polls only the ids on this page every 30 s and
        // swaps the badge text/colour in place, so a tab left open never says
        // "online" for someone who left half an hour ago.
        document.addEventListener('alpine:init', () => {
            Alpine.data('presence', (ids, url, badge, dot) => ({
                del: { open: false, id: null, name: '', username: '', url: '' },
                timer: null,
                init() {
                    if (ids.length) this.timer = setInterval(() => this.refresh(), 30000);
                    document.addEventListener('visibilitychange', () => { if (!document.hidden) this.refresh(); });
                },
                askDelete(id, name, username, url) { this.del = { open: true, id, name, username, url }; },
                async refresh() {
                    if (document.hidden) return;
                    try {
                        const res = await fetch(`${url}?ids=${ids.join(',')}`, { headers: { Accept: 'application/json' } });
                        if (!res.ok) return;
                        const { users } = await res.json();
                        for (const [id, info] of Object.entries(users)) {
                            const el = document.querySelector(`[data-presence="${id}"]`);
                            if (!el) continue;
                            el.className = 'badge ' + (badge[info.status] || badge.offline);
                            el.title = info.last_seen ? `Last seen ${info.last_seen}` : 'Never signed in';
                            el.firstElementChild.className = dot[info.status] || dot.offline;
                            el.querySelector('[data-presence-label]').textContent = info.status;
                        }
                    } catch (e) { /* network hiccup — keep the last known state */ }
                },
            }));
        });
    </script>
</x-app-layout>
