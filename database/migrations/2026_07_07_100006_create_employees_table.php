<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            // link to the login account (roles live on the User via spatie/permission)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_no')->nullable()->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->enum('employee_type', ['admin', 'technical'])->default('admin');
            $table->foreignId('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            // supervisor / department head (self-referencing) for approval routing
            $table->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->decimal('monthly_salary', 12, 2)->default(0);
            $table->decimal('daily_rate', 12, 2)->default(0);
            $table->date('date_hired')->nullable();
            $table->enum('status', ['active', 'inactive', 'on_leave'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
