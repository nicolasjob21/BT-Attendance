<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            // HR verification of an unusually long day (13h+). Stamped on the
            // day's closing time-out log. Null status = still awaiting review.
            $table->enum('ot_verification_status', ['approved', 'rejected'])->nullable()->after('within_geofence');
            $table->text('ot_remarks')->nullable()->after('ot_verification_status'); // HR's note on the reason for the OT
            $table->foreignId('ot_verified_by')->nullable()->after('ot_remarks')->constrained('users')->nullOnDelete();
            $table->timestamp('ot_verified_at')->nullable()->after('ot_verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ot_verified_by');
            $table->dropColumn(['ot_verification_status', 'ot_remarks', 'ot_verified_at']);
        });
    }
};
