<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per employee per campaign: their response to the shared
        // checkpoint. Created when the campaign is activated. The evidence
        // captured on submission is frozen; HR review lives in a separate table.
        Schema::create('checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('checkpoint_campaigns')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('project_site_id')->constrained('sites')->restrictOnDelete();

            // pending | notified | responded | missed | outside_geofence | gps_unavailable |
            // camera_permission_denied | submission_failed | pending_review |
            // approved_exception | rejected_exception
            $table->string('status', 30)->default('pending');
            // For successful responses: completed | completed_with_low_gps_accuracy | completed_after_review
            $table->string('verification_result', 40)->nullable();
            $table->string('failure_reason', 40)->nullable();     // last validation outcome code
            $table->string('validation_message')->nullable();

            // Notification & attempts
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('seen_at')->nullable();              // employee opened the checkpoint page
            $table->unsignedSmallInteger('submission_attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->string('last_attempt_result', 40)->nullable();
            $table->string('issue_reported', 40)->nullable();     // no_internet | camera_denied | gps_unavailable | device_problem

            // Evidence frozen at the accepted (or last) submission.
            $table->dateTime('submitted_at')->nullable();         // server time of the accepted submission
            $table->dateTime('server_timestamp')->nullable();
            $table->dateTime('client_timestamp')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('gps_accuracy_meters', 10, 2)->nullable();
            $table->decimal('distance_from_site_meters', 10, 2)->nullable();
            $table->foreignId('matched_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->boolean('within_geofence')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('network_status', 20)->nullable();

            // Follow-up (denormalised from the latest review record for listing).
            $table->text('employee_explanation')->nullable();
            $table->string('hr_reason', 40)->nullable();
            $table->text('hr_note')->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'employee_id']);
            $table->index(['employee_id', 'status']);
            $table->index(['campaign_id', 'status']);
            $table->index(['status', 'updated_at']);
            $table->index('project_site_id');
        });

        // HR follow-up actions are stored as separate, append-only review
        // records so the original evidence is never overwritten.
        Schema::create('checkpoint_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkpoint_id')->constrained('checkpoints')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            // explanation_recorded | note_added | marked_for_review | approved | rejected | escalated
            $table->string('action', 30);
            $table->string('reason', 40)->nullable();
            $table->text('explanation')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['checkpoint_id', 'created_at']);
        });

        // Append-only trail of every campaign / checkpoint action.
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
        Schema::dropIfExists('checkpoint_reviews');
        Schema::dropIfExists('checkpoints');
    }
};
