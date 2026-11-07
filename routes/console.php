<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The RDAP bootstrap registry and the Public Suffix List change rarely; workers fall back to the shipped snapshots.
Schedule::command('intrawatch:rdap:refresh')->weekly();

// The lock expires after ten minutes, so a crashed run cannot block rollups for long.
Schedule::command('intrawatch:rollup')->everyMinute()->withoutOverlapping(10)->onOneServer();

// Hourly pruning keeps each run small; deletes are chunked and paced. The first prune of a large backlog can
// take longer than an hour, so its lock outlives the next two slots.
Schedule::command('intrawatch:prune')->hourlyAt(30)->withoutOverlapping(180)->onOneServer();

// Degraded holds, reminders, flap ends and suppression re-checks. Signals from the checker are evaluated by queued
// jobs; this only covers what falls due with time.
Schedule::command('intrawatch:alerts:tick')->everyFifteenSeconds()->withoutOverlapping(2)->onOneServer();
