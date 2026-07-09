<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();

            // earnings
            $table->decimal('basic_pay', 12, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('night_diff_pay', 12, 2)->default(0);
            $table->decimal('holiday_pay', 12, 2)->default(0);
            $table->decimal('gross_pay', 12, 2)->default(0);

            // deductions
            $table->decimal('late_undertime_deduction', 12, 2)->default(0);
            $table->decimal('absences_deduction', 12, 2)->default(0);
            $table->decimal('sss_deduction', 12, 2)->default(0);
            $table->decimal('philhealth_deduction', 12, 2)->default(0);
            $table->decimal('pagibig_deduction', 12, 2)->default(0);
            $table->decimal('withholding_tax', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);

            $table->decimal('net_pay', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'payroll_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
    }
};
