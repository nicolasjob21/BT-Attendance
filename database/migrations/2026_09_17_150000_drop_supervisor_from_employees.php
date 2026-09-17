<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The supervisor field was reserved for approval routing that was never built;
 * approvals go to everyone with `approve requests`. Removed to keep the
 * employee form honest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('supervisor_id')->nullable()->after('schedule_id')->constrained('employees')->nullOnDelete();
        });
    }
};
