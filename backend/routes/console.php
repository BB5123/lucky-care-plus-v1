<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ADR-016 §5: daily retention purge, and the inactivity scan one hour later.
Schedule::command('retention:purge')
    ->dailyAt('03:00')
    ->timezone('UTC')
    ->withoutOverlapping();

Schedule::command('retention:inactivity')
    ->dailyAt('04:00')
    ->timezone('UTC')
    ->withoutOverlapping();
