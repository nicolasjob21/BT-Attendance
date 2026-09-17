<?php

use App\Support\RoleMatrix;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Four account categories — Superadmin · Developer · Admin · Employee — per
 * BT-Attendance-Role-Permissions.xlsx. Renames the old `hr` role to `admin`
 * (users keep their assignment), creates `developer`, adds the new permissions,
 * and applies the spreadsheet defaults to every role.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::table('roles')->where('name', 'hr')->where('guard_name', 'web')->update(['name' => RoleMatrix::ADMIN]);

        RolePermissionSeeder::syncDefaults();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::table('roles')->where('name', RoleMatrix::ADMIN)->where('guard_name', 'web')->update(['name' => 'hr']);
        Role::where('name', RoleMatrix::DEVELOPER)->delete();
    }
};
