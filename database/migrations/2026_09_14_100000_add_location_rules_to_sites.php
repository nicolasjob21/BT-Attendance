<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // A site is an authorized attendance location. The main office is
            // permanent; project sites come and go; temporary sites (training
            // venue, etc.) may carry an activation window.
            $table->enum('type', ['office', 'project_site', 'temporary'])->default('project_site')->after('name');
            // Completed/inactive sites stop matching new punches but are never
            // deleted, so historical attendance keeps pointing at them.
            $table->enum('status', ['active', 'inactive', 'completed'])->default('active')->after('is_headquarters');
            $table->date('active_from')->nullable()->after('status');
            $table->date('active_until')->nullable()->after('active_from');
            $table->foreignId('created_by')->nullable()->after('active_until')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        // Existing headquarters rows are the main office.
        DB::table('sites')->where('is_headquarters', true)->update(['type' => 'office']);
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['type', 'status', 'active_from', 'active_until']);
        });
    }
};
