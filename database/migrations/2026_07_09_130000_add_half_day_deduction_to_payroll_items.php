<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            // Pay withheld for approved half-day leave (0.5 × daily rate per half day).
            $table->decimal('half_day_deduction', 12, 2)->default(0)->after('absences_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn('half_day_deduction');
        });
    }
};
