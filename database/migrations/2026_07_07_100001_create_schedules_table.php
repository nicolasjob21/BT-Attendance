<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // e.g. "Admin 8:30-5:30", "Flexible"
            $table->time('time_in')->nullable();
            $table->time('time_out')->nullable();
            $table->unsignedSmallInteger('grace_minutes')->default(0); // late grace period
            $table->boolean('is_flexible')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
