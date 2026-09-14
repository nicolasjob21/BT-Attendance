<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            // Location evidence captured at the moment of the punch. site_id is
            // the location the employee was actually matched to; assigned_site_id
            // is where they were *supposed* to be. Both are frozen at punch time.
            $table->foreignId('assigned_site_id')->nullable()->after('site_id')->constrained('sites')->nullOnDelete();
            $table->decimal('gps_accuracy_m', 10, 2)->nullable()->after('distance_m');
            // verified_location | authorized_alternate_location | outside_authorized_area | gps_unavailable | low_accuracy
            $table->string('location_status', 40)->nullable()->after('within_geofence');
            $table->string('location_validation_message')->nullable()->after('location_status');
            // Employee's own explanation when punching outside every geofence.
            $table->text('location_reason')->nullable()->after('location_validation_message');

            // HR review of an out-of-area / low-accuracy punch. Null = nothing to review.
            $table->enum('location_verification_status', ['pending', 'approved', 'rejected'])->nullable()->after('location_reason');
            $table->text('location_remarks')->nullable()->after('location_verification_status');
            $table->foreignId('location_verified_by')->nullable()->after('location_remarks')->constrained('users')->nullOnDelete();
            $table->timestamp('location_verified_at')->nullable()->after('location_verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_site_id');
            $table->dropConstrainedForeignId('location_verified_by');
            $table->dropColumn([
                'gps_accuracy_m', 'location_status', 'location_validation_message', 'location_reason',
                'location_verification_status', 'location_remarks', 'location_verified_at',
            ]);
        });
    }
};
