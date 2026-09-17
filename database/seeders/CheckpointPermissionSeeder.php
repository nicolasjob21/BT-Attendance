<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Kept for the documented command `php artisan db:seed --class=CheckpointPermissionSeeder`.
 * Check Point permissions now live in App\Support\RoleMatrix with everything else.
 */
class CheckpointPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
    }
}
