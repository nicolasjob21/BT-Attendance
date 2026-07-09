<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurable government contribution brackets (NOT hardcoded) so the
        // yearly SSS / PhilHealth / Pag-IBIG changes are a data update, not a code change.
        Schema::create('contribution_rates', function (Blueprint $table) {
            $table->id();
            $table->enum('contribution_type', ['sss', 'philhealth', 'pagibig']);
            $table->decimal('min_salary', 12, 2)->default(0);
            $table->decimal('max_salary', 12, 2)->nullable(); // null = no ceiling
            $table->decimal('employee_rate', 6, 4)->default(0); // fraction, e.g. 0.0500 = 5%
            $table->decimal('employer_rate', 6, 4)->default(0);
            $table->decimal('ec_amount', 8, 2)->default(0);     // SSS employer EC contribution
            $table->unsignedSmallInteger('effective_year');
            $table->timestamps();

            $table->index(['contribution_type', 'effective_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_rates');
    }
};
