<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('lgu-dromic:remind-signed-copies --days=3')
    ->dailyAt('08:00')
    ->withoutOverlapping();

Schedule::command('lgu-dromic:remind-alert-deadlines --hours=2')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('epirma:sync-document-status --cache-signed')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('wit:sync --trigger=automatic')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('ris:sync --trigger=automatic')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->when(fn (): bool => (bool) config('services.google_sheets.ris_auto_sync_enabled', false));

Schedule::command('stf:sync --trigger=automatic')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->when(fn (): bool => (bool) config('services.google_sheets.stf_auto_sync_enabled', true));

Schedule::command('dispatch:sync-contacts')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
