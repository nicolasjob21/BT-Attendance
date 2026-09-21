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

// Payroll: keep the semi-monthly periods rolling and, on pay day (the 15th
// and the last day of the month), remind everyone who runs payroll first
// thing in the morning — the office pays on the cutoff's own last day, so
// payroll is computed, reviewed and released by a person during that day.
Schedule::command('payroll:tick')->dailyAt('07:00')->withoutOverlapping();
