<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            // When the "pay day today" reminder went out (sent once per period).
            $table->timestamp('reminded_at')->nullable()->after('generated_by');
        });

        // The automation switch is gone: payroll is run by a person on pay day.
        DB::table('settings')->where('key', 'payroll.auto_enabled')->delete();
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
