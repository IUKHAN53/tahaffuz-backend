<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily push reminders for children with overdue / soon-due vaccines.
// Requires the scheduler to run (cron: * * * * * php artisan schedule:run).
Schedule::command('vaccines:remind')->dailyAt('09:00');
