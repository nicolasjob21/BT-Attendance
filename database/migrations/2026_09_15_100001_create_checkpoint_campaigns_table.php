<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A temporary "random presence verification" session HR/Admin switches
        // on for one project site when they suspect staff leave during the day.
        Schema::create('checkpoint_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('project_site_id')->constrained('sites')->restrictOnDelete();
            $table->text('reason');

            $table->date('start_date');
            $table->date('end_date');
            $table->time('working_start_time');
            $table->time('working_end_time');
            $table->boolean('include_weekends')->default(false);

            $table->unsignedTinyInteger('checkpoints_per_day');
            $table->unsignedSmallInteger('minimum_interval_minutes');
            $table->unsignedSmallInteger('maximum_interval_minutes');
            $table->unsignedSmallInteger('response_window_minutes');
            // Rotating photo instructions, one is picked per checkpoint.
            $table->json('photo_instructions');

            // draft | scheduled | active | paused | completed | cancelled
            $table->string('status', 20)->default('draft');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['project_site_id', 'status']);
            $table->index(['start_date', 'end_date']);
        });

        // Employees covered by a campaign. Kept as a pivot (not JSON) so the
        // dispatcher and reports can join on it.
        Schema::create('checkpoint_campaign_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('checkpoint_campaigns')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'employee_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkpoint_campaign_participants');
        Schema::dropIfExists('checkpoint_campaigns');
    }
};
