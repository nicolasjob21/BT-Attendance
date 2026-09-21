<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two new payroll deductions, both repaid per cutoff until a balance is
     * cleared: company loans / cash advances, and items that went missing
     * at a site, charged to the employees responsible.
     */
    public function up(): void
    {
        Schema::create('payroll_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // loan | missing_item
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_id', 40)->nullable()->index(); // one missing-item incident split across several employees
            $table->string('description');
            $table->date('incident_date')->nullable();
            $table->decimal('total_amount', 12, 2);
            $table->decimal('installment_amount', 12, 2);
            $table->decimal('balance', 12, 2);
            $table->date('starts_on'); // first cutoff ending on/after this date
            $table->string('status', 20)->default('active'); // active | paid | cancelled
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('payroll_deduction_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_deduction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        Schema::table('payroll_items', function (Blueprint $table) {
            $table->decimal('loan_deduction', 12, 2)->default(0)->after('other_deductions');
            $table->decimal('missing_item_deduction', 12, 2)->default(0)->after('loan_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn(['loan_deduction', 'missing_item_deduction']);
        });
        Schema::dropIfExists('payroll_deduction_payments');
        Schema::dropIfExists('payroll_deductions');
    }
};
