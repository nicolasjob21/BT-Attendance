<?php

namespace App\Http\Controllers;

use App\Support\RoleMatrix;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles & Permissions (`manage roles`): the spreadsheet matrix, live. One
 * checkbox per role × permission; saving syncs the role's permissions.
 */
class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::whereIn('name', RoleMatrix::ROLES)->with('permissions:id,name')->get()->keyBy('name');

        return view('users.roles', [
            'roles' => $roles,
            'modules' => RoleMatrix::byModule(),
            'granted' => $roles->map(fn (Role $r) => $r->permissions->pluck('name')->flip()->map(fn () => true)->all()),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'grants' => ['nullable', 'array'],
            'grants.*' => ['array'],
            'grants.*.*' => ['string', 'in:1'],
        ]);

        $allowed = array_keys(RoleMatrix::PERMISSIONS);

        foreach (RoleMatrix::ROLES as $name) {
            $wanted = array_values(array_intersect(array_keys($data['grants'][$name] ?? []), $allowed));

            // The owner can never be locked out of the two access-control permissions.
            if ($name === RoleMatrix::SUPERADMIN) {
                $wanted = array_values(array_unique([...$wanted, ...RoleMatrix::LOCKED_SUPERADMIN]));
            }

            Role::findByName($name, 'web')->syncPermissions($wanted);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('roles.index')->with('status', 'Role permissions saved. Changes apply to everyone on their next page load.');
    }

    /** Back to the spreadsheet defaults. */
    public function reset()
    {
        RolePermissionSeeder::syncDefaults();

        return redirect()->route('roles.index')->with('status', 'Role permissions reset to the defaults.');
    }
}
