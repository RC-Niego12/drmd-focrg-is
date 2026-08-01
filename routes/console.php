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
