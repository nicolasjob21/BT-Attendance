<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Check Point module clock: opens random checkpoints on time and marks lapsed
// ones as missed. Needs `php artisan schedule:work` (or a cron running
// `schedule:run` every minute) in production.
Schedule::command('checkpoints:tick')->everyMinute()->withoutOverlapping();

// Payroll: keep the semi-monthly periods rolling and, when the Super Admin has
// switched automation on, generate payroll the day after a cutoff ends.
Schedule::command('payroll:tick')->dailyAt('01:00')->withoutOverlapping();
