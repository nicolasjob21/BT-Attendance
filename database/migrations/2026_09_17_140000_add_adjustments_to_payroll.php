<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll automation + per-employee editing.
 *  - payroll_items: allowances / other deductions and a "manually adjusted"
 *    stamp so an auto-run never overwrites a line HR has edited by hand.
 *  - payroll_periods: who generated / closed it and when, so the audit is on
 *    the period itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->decimal('allowances', 12, 2)->default(0)->after('holiday_pay');
            $table->decimal('other_deductions', 12, 2)->default(0)->after('withholding_tax');
            $table->string('remarks', 500)->nullable()->after('net_pay');
            $table->timestamp('adjusted_at')->nullable()->after('remarks');
            $table->foreignId('adjusted_by')->nullable()->after('adjusted_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->timestamp('generated_at')->nullable()->after('status');
            $table->string('generated_by', 20)->nullable()->after('generated_at'); // 'auto' or a user id
            $table->timestamp('closed_at')->nullable()->after('generated_by');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjusted_by');
            $table->dropColumn(['allowances', 'other_deductions', 'remarks', 'adjusted_at']);
        });
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['generated_at', 'generated_by', 'closed_at']);
        });
    }
};
