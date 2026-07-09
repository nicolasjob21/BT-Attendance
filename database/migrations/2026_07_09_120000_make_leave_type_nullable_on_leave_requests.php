<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A half-day request may be filed without a leave type — the context
        // (e.g. the client) goes in the reason instead.
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('leave_type_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('leave_type_id')->nullable(false)->change();
        });
    }
};
