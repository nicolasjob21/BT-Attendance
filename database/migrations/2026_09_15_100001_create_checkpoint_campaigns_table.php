<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ONE shared live presence checkpoint for one project site. Every
        // selected employee gets the same official start time and deadline,
        // both set by the server when HR activates it.
        Schema::create('checkpoint_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('project_site_id')->constrained('sites')->restrictOnDelete();
            $table->string('instruction');                       // e.g. "Capture the project entrance."
            $table->text('reason')->nullable();                  // why HR is running this check
            $table->unsignedSmallInteger('response_window_minutes');

            // Optional planned start (HR "set the checkpoint start time"); the
            // dispatcher activates it at that moment. Null = activate manually.
            $table->dateTime('scheduled_start_at')->nullable();
            // Official, server-generated, shared by every employee.
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('expires_at')->nullable();

            // draft | active | paused | expired | completed | cancelled
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
            $table->index(['status', 'expires_at']);
            $table->index('scheduled_start_at');
        });

        // Employees covered by a campaign (kept as a pivot so the dispatcher
        // and monitoring page can join on it).
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
