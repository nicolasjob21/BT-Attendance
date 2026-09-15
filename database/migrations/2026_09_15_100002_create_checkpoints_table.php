<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One random presence check for one employee. Times are generated on
        // the server; the employee only learns about it when it opens.
        Schema::create('checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('checkpoint_campaigns')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('project_site_id')->constrained('sites')->restrictOnDelete();

            $table->date('scheduled_for');            // working day the check belongs to
            $table->dateTime('scheduled_at');         // secret server-generated time
            $table->string('photo_instruction');

            $table->dateTime('opened_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('server_timestamp')->nullable();
            $table->dateTime('client_timestamp')->nullable();

            // Evidence frozen at submission. Not editable by the employee.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('gps_accuracy_meters', 10, 2)->nullable();
            $table->decimal('distance_from_site_meters', 10, 2)->nullable();
            $table->foreignId('matched_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->boolean('within_geofence')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('network_status', 20)->nullable(); // online | offline_synced

            // scheduled | open | submitted | verified | failed | missed | expired | pending_review | cancelled
            $table->string('verification_status', 20)->default('scheduled');
            // verified_presence | outside_geofence | gps_unavailable | low_gps_accuracy | photo_missing |
            // checkpoint_expired | duplicate_submission | unauthorized_employee | pending_review | campaign_paused ...
            $table->string('failure_reason', 40)->nullable();
            $table->string('validation_message')->nullable();

            // Exception review (missed / failed / pending). Null = nothing to review.
            $table->text('employee_explanation')->nullable();
            $table->string('review_status', 20)->nullable(); // pending | reviewed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_result', 40)->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'scheduled_for']);
            $table->index(['employee_id', 'verification_status']);
            $table->index(['verification_status', 'scheduled_at']);
            $table->index(['verification_status', 'expires_at']);
            $table->index(['review_status', 'created_at']);
            $table->index('project_site_id');
        });

        // Append-only trail of every campaign / review action.
        Schema::create('checkpoint_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained('checkpoint_campaigns')->cascadeOnDelete();
            $table->foreignId('checkpoint_id')->nullable()->constrained('checkpoints')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['campaign_id', 'created_at']);
            $table->index(['checkpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkpoint_audit_logs');
        Schema::dropIfExists('checkpoints');
    }
};
