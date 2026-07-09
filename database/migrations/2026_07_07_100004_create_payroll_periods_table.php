<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date')->nullable();
            // semi-monthly cutoff: 1st-15th or 16th-end of month
            $table->enum('cutoff_type', ['first_half', 'second_half']);
            $table->enum('status', ['open', 'processing', 'closed'])->default('open');
            $table->timestamps();

            $table->unique(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_periods');
    }
};
