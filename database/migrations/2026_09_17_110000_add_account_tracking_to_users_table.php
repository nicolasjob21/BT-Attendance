<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns behind the User Management page: presence (last_seen_at), sign-in
     * history, and the two account switches — disabled (blocked from logging in)
     * and soft-deleted (hidden everywhere, restorable).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('remember_token');
            $table->timestamp('last_login_at')->nullable()->after('last_seen_at');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->timestamp('disabled_at')->nullable()->after('last_login_ip');
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['last_seen_at', 'last_login_at', 'last_login_ip', 'disabled_at']);
        });
    }
};
