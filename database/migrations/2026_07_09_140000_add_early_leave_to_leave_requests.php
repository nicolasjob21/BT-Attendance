<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Mid-shift "go home early / sick" request: a half-day (PM) filed in
            // real time while already clocked in, with the time the employee wants
            // to leave. Reuses the half-day approval + salary logic.
            $table->boolean('is_early_leave')->default(false)->after('day_portion');
            $table->time('requested_time_out')->nullable()->after('is_early_leave');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['is_early_leave', 'requested_time_out']);
        });
    }
};
