<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete(); // trip destination
            $table->date('ot_date');
            $table->decimal('hours', 5, 2);
            $table->enum('ot_type', ['regular', 'rest_day', 'holiday'])->default('regular');
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'denied'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
    }
};
