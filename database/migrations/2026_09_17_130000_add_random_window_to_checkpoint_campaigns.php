<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Let the system pick the time": the admin gives a date and a window
 * (e.g. 8:30 AM – 5:30 PM); the server draws the start time at random inside
 * it. The window is kept so the campaign page can show the admin what the
 * time was drawn from, and re-draw with the same rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkpoint_campaigns', function (Blueprint $table) {
            $table->string('schedule_mode', 10)->nullable()->after('scheduled_start_at'); // manual | random
            $table->time('random_window_start')->nullable()->after('schedule_mode');
            $table->time('random_window_end')->nullable()->after('random_window_start');
        });
    }

    public function down(): void
    {
        Schema::table('checkpoint_campaigns', function (Blueprint $table) {
            $table->dropColumn(['schedule_mode', 'random_window_start', 'random_window_end']);
        });
    }
};
