<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            // Overtime is requested BEFORE it is worked: the employee states the
            // planned window and what the OT is for; Admin/HR approves or denies.
            $table->time('planned_start')->nullable()->after('ot_date');
            $table->time('planned_end')->nullable()->after('planned_start');
            $table->decimal('requested_hours', 5, 2)->nullable()->after('planned_end');
            // Actual hours are derived from attendance once the employee has
            // clocked out on the OT date; null until then.
            $table->decimal('hours', 5, 2)->nullable()->change();
            $table->timestamp('hours_synced_at')->nullable()->after('hours');
            $table->text('admin_remarks')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->dropColumn(['planned_start', 'planned_end', 'requested_hours', 'hours_synced_at', 'admin_remarks']);
            $table->decimal('hours', 5, 2)->nullable(false)->change();
        });
    }
};
