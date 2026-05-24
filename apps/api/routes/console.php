<?php

declare(strict_types=1);

use App\Jobs\ArchiveConsentEventsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly D1 → MySQL drain. Keeps D1 bounded to the last 90 days of
// hot writes while the MySQL `consent_events` archive holds the
// canonical record indefinitely.
Schedule::job(new ArchiveConsentEventsJob)
    ->dailyAt('03:00')
    ->name('archive-consent-events')
    ->onOneServer()
    ->withoutOverlapping();

// Daily sweep that dispatches RunScanJob for every domain whose cadence
// is due today. Spread across the cadence window so the scanner worker
// isn't hammered. See DispatchScheduledScans for the stagger algorithm.
Schedule::command('scans:dispatch-scheduled')
    ->dailyAt('04:00')
    ->name('dispatch-scheduled-scans')
    ->onOneServer()
    ->withoutOverlapping();

// Daily purge of consent events past each org's configured retention
// window. Runs at 02:00 — before the 03:00 archive so the two never
// race for the same rows (purge only touches the MySQL archive; D1
// is rotated independently by the archive job).
Schedule::command('consent-events:purge')
    ->dailyAt('02:00')
    ->name('purge-consent-events')
    ->onOneServer()
    ->withoutOverlapping();
