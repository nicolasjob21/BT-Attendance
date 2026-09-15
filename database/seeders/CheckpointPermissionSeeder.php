<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Check Point module permissions. Idempotent — safe to run on an existing
 * database: `php artisan db:seed --class=CheckpointPermissionSeeder`.
 */
class CheckpointPermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'view checkpoint module',
        'create checkpoint campaign',
        'activate checkpoint campaign',
        'pause checkpoint campaign',
        'end checkpoint campaign',
        'view checkpoint results',
        'review checkpoint exceptions',
        'export checkpoint reports',
        'manage checkpoint settings',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $p) {
            Permission::findOrCreate($p, 'web');
        }

        // HR and the super admin run campaigns and review exceptions;
        // employees only respond to checkpoints (no permission needed).
        foreach (['hr', 'superadmin'] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo(self::PERMISSIONS);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
