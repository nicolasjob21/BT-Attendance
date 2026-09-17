<?php

namespace Database\Seeders;

use App\Support\RoleMatrix;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission and role from RoleMatrix. Idempotent:
 * `php artisan db:seed --class=RolePermissionSeeder`.
 *
 * Roles that already exist keep whatever the Roles & Permissions page has set;
 * only brand-new roles (and any permission missing from every role on first
 * run) get the spreadsheet defaults. Pass `--force-defaults` semantics by
 * calling ::syncDefaults() directly.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(RoleMatrix::PERMISSIONS) as $p) {
            Permission::findOrCreate($p, 'web');
        }

        foreach (RoleMatrix::ROLES as $name) {
            $role = Role::where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                Role::create(['name' => $name, 'guard_name' => 'web'])
                    ->syncPermissions(RoleMatrix::DEFAULTS[$name]);
            }
        }

        // Nothing may strip the owner of the two permissions that manage access itself.
        Role::findByName(RoleMatrix::SUPERADMIN, 'web')->givePermissionTo(RoleMatrix::LOCKED_SUPERADMIN);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Reset every role to the spreadsheet defaults (fresh installs, tests). */
    public static function syncDefaults(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(RoleMatrix::PERMISSIONS) as $p) {
            Permission::findOrCreate($p, 'web');
        }
        foreach (RoleMatrix::DEFAULTS as $name => $perms) {
            Role::findOrCreate($name, 'web')->syncPermissions($perms);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
