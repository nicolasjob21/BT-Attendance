<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One office schedule for everyone: fixed 8:30 AM – 5:30 PM. The "Technical
 * (flexible)" schedule and the admin/technical employee type are retired —
 * every employee is moved to the fixed schedule and flexible schedules are
 * removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fixed = DB::table('schedules')->where('is_flexible', false)->orderBy('id')->first();
        if (! $fixed) {
            $id = DB::table('schedules')->insertGetId([
                'name' => 'Office (8:30 AM – 5:30 PM)', 'time_in' => '08:30:00', 'time_out' => '17:30:00',
                'grace_minutes' => 15, 'is_flexible' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            $id = $fixed->id;
        }

        DB::table('employees')->update(['schedule_id' => $id, 'employee_type' => 'admin']);
        DB::table('schedules')->where('is_flexible', true)->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the flexible schedule was a seed, not user data.
    }
};
