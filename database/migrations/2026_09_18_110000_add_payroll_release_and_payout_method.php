<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Release step: the Super Admin presses "Release payroll" once salaries are
 * out; only then do employees see their payslips. Each employee is paid by
 * card (direct to the card) or cash (printed payslip in the envelope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('closed_by');
            $table->foreignId('released_by')->nullable()->after('released_at')->constrained('users')->nullOnDelete();
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->string('payout_method', 10)->default('cash')->after('monthly_salary'); // card | cash
            $table->string('bank_account_no', 40)->nullable()->after('payout_method');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('released_by');
            $table->dropColumn('released_at');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['payout_method', 'bank_account_no']);
        });
    }
};
