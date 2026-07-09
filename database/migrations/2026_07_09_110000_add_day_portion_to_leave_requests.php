<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Whether the request is a whole day or a half day (morning/afternoon).
            // A half day is always a single date worth 0.5 days.
            $table->enum('day_portion', ['full', 'half_am', 'half_pm'])->default('full')->after('date_to');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('day_portion');
        });
    }
};
