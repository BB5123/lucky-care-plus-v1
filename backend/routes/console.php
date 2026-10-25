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

// P1 (RETENTION-MODEL.md §8): physical deletion runs on its own clock - a row
// must have been soft-deleted for the grace period before it is removed - so
// it runs after the purge and never compacts the same night's soft deletes.
Schedule::command('retention:compact')
    ->dailyAt('03:20')
    ->timezone('UTC')
    ->withoutOverlapping();

Schedule::command('retention:inactivity')
    ->dailyAt('04:00')
    ->timezone('UTC')
    ->withoutOverlapping();

// P1.6b: reminder side of the notification slice. Both commands are
// idempotent through the delivery ledger, not through the queue.
Schedule::command('notifications:send-due')
    ->hourly()
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping();

Schedule::command('notifications:send-appointment-reminders')
    ->dailyAt('09:00')
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping();

// P2.1: materialise recurring care-task occurrences just after midnight so the
// day's work exists before the hourly reminder scan runs.
Schedule::command('tasks:materialise')
    ->dailyAt('00:15')
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping();

// P2.2: the escalation ladder looks for overdue delegated/clinical tasks every
// 15 minutes. The escalator itself is idempotent (`escalation_level >= target`),
// so a delayed run never double-fires a rung.
Schedule::command('tasks:escalate')
    ->everyFifteenMinutes()
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping();
