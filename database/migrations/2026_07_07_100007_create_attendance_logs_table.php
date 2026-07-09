<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->enum('log_type', ['time_in', 'time_out']);
            $table->timestamp('logged_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // server-computed Haversine distance from the site + geofence verdict
            $table->decimal('distance_m', 10, 2)->nullable();
            $table->boolean('within_geofence')->default(false);
            $table->string('photo_path')->nullable();          // selfie
            $table->boolean('synced_offline')->default(false); // captured offline, synced later
            $table->timestamps();

            $table->index(['employee_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
