<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\RoleMatrix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * User Management (superadmin, `manage users`): every login account with its
 * username, role, sign-in history and a live Online/Offline status. Employee
 * details (salary, schedule, site) live under Employees; this page is about the
 * account itself — who can sign in, as what, and whether they are here now.
 */
class UserController extends Controller
{
    public const STATUSES = ['online', 'offline', 'disabled', 'deleted'];

    public function index(Request $request)
    {
        $search = $request->string('search')->trim()->toString();
        $role = $request->string('role')->toString();
        $status = in_array($request->string('status')->toString(), self::STATUSES, true)
            ? $request->string('status')->toString()
            : '';

        $users = User::query()
            ->with(['roles:id,name', 'employee:id,user_id,employee_no,status'])
            ->when($status === 'deleted', fn ($q) => $q->onlyTrashed())
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($role, fn ($q) => $q->role($role))
            ->when($status === 'online', fn ($q) => $q->online())
            ->when($status === 'offline', fn ($q) => $q->whereNull('disabled_at')->where(fn ($w) => $w
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<=', now()->subSeconds(User::PRESENCE_ONLINE_WITHIN))))
            ->when($status === 'disabled', fn ($q) => $q->whereNotNull('disabled_at'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $counts = [
            'total' => User::count(),
            'online' => User::online()->count(),
            'disabled' => User::whereNotNull('disabled_at')->count(),
            'deleted' => User::onlyTrashed()->count(),
        ];

        return view('users.index', [
            'users' => $users,
            'counts' => $counts,
            'roles' => collect(RoleMatrix::ROLES)->mapWithKeys(fn ($r) => [$r => RoleMatrix::ROLE_LABELS[$r]]),
            'search' => $search,
            'role' => $role,
            'status' => $status,
            'onlineWithin' => User::PRESENCE_ONLINE_WITHIN,
        ]);
    }

    /**
     * Live Online/Offline for the rows on screen (`?ids=1,2,3`), polled by the
     * index page so a tab left open stops claiming someone is here after they leave.
     */
    public function presence(Request $request)
    {
        $ids = collect(explode(',', $request->string('ids')->toString()))
            ->filter(fn ($id) => ctype_digit($id))
            ->map(fn ($id) => (int) $id)
            ->take(100);

        $rows = User::withTrashed()->whereIn('id', $ids)->get(['id', 'last_seen_at', 'disabled_at', 'deleted_at']);

        return response()->json([
            'users' => $rows->mapWithKeys(fn (User $u) => [$u->id => [
                'status' => $u->presenceStatus(),
                'last_seen' => $u->last_seen_at?->diffForHumans(),
            ]]),
        ]);
    }

    public function edit(User $user)
    {
        $user->load(['roles:id,name', 'employee:id,user_id,employee_no']);

        return view('users.edit', [
            'user' => $user,
            'roles' => $this->assignableRoles(request()->user(), $user),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $request->merge(['username' => User::normalizeUsername($request->input('username'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:60', 'regex:'.User::USERNAME_PATTERN,
                Rule::unique('users', 'username')->ignore($user->id)->withoutTrashed(),
            ],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id)->withoutTrashed(),
            ],
            'role' => ['required', Rule::exists('roles', 'name')],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $current = $user->getRoleNames()->first();
        if ($data['role'] !== $current && ! in_array($data['role'], RoleMatrix::assignableBy($request->user()->getRoleNames()->first()), true)) {
            return back()->withInput()->withErrors(['role' => 'You cannot assign the '.RoleMatrix::ROLE_LABELS[$data['role']].' role.']);
        }
        if ($user->is($request->user()) && $data['role'] !== $current) {
            return back()->withInput()->withErrors(['role' => 'You cannot change your own role.']);
        }
        if ($this->isLastSuperadmin($user) && $data['role'] !== 'superadmin') {
            return back()->withInput()->withErrors(['role' => 'At least one Super Admin account must remain.']);
        }

        DB::transaction(function () use ($user, $data) {
            $user->update([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
            ]);
            if (! empty($data['password'])) {
                $user->setTemporaryPassword($data['password']);
            }
            $user->syncRoles([$data['role']]);

            // Keep the HR profile's name and email in step with the account.
            if ($user->employee) {
                [$first, $last] = array_pad(explode(' ', $data['name'], 2), 2, '');
                $user->employee->update(['first_name' => $first, 'last_name' => $last, 'email' => $data['email']]);
            }
        });

        return redirect()->route('users.index')->with('status', "Account {$user->username} updated.");
    }

    /** Block or unblock sign-in without deleting anything. */
    public function toggleDisabled(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['account' => 'You cannot disable your own account.']);
        }

        if ($user->isDisabled()) {
            $user->update(['disabled_at' => null]);

            return back()->with('status', "{$user->username} can sign in again.");
        }

        if ($this->isLastSuperadmin($user)) {
            return back()->withErrors(['account' => 'At least one Super Admin account must remain enabled.']);
        }

        $user->update(['disabled_at' => now()]);
        DB::table('sessions')->where('user_id', $user->id)->delete(); // sign them out everywhere now

        return back()->with('status', "{$user->username} is disabled and signed out.");
    }

    /** Soft delete — the account disappears from every list and cannot sign in, but can be restored. */
    public function destroy(Request $request, User $user)
    {
        $request->validate(['password' => ['required', 'current_password']]);

        if ($user->is($request->user())) {
            return back()->withErrors(['account' => 'You cannot delete your own account.']);
        }
        if ($this->isLastSuperadmin($user)) {
            return back()->withErrors(['account' => 'At least one Super Admin account must remain.']);
        }

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->delete();

        return redirect()->route('users.index')->with('status', "Account {$user->username} deleted. It can be restored from the Deleted filter.");
    }

    public function restore(int $id)
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $user->restore();

        return redirect()->route('users.index')->with('status', "Account {$user->username} restored.");
    }

    /**
     * Roles the actor may set on this account: what they are allowed to hand
     * out, plus the account's current role so the form can be saved unchanged.
     *
     * @return array<string, string> role => label
     */
    private function assignableRoles(User $actor, User $target): array
    {
        $roles = RoleMatrix::assignableBy($actor->getRoleNames()->first());
        if ($current = $target->getRoleNames()->first()) {
            $roles[] = $current;
        }

        return collect(RoleMatrix::ROLES)
            ->filter(fn ($r) => in_array($r, $roles, true))
            ->mapWithKeys(fn ($r) => [$r => RoleMatrix::ROLE_LABELS[$r]])
            ->all();
    }

    private function isLastSuperadmin(User $user): bool
    {
        return $user->hasRole('superadmin')
            && User::role('superadmin')->whereNull('disabled_at')->where('id', '!=', $user->id)->doesntExist();
    }
}
