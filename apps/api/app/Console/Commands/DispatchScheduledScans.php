<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ScanCadence;
use App\Enums\ScanTrigger;
use App\Models\Domain;
use App\Services\Scanner\ScanRunner;
use App\Services\Scanner\SitemapDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Daily entry point for scheduled domain scans. Loops every domain whose
 * scan_cadence isn't Never; dispatches a queued scan for each one that's
 * due today.
 *
 * "Due today" combines two checks:
 *   - Recency: last_scanned_at older than cadence intervalDays (always
 *     true for never-scanned domains).
 *   - Stagger: crc32($domainId) % staggerWindow matches today's slot,
 *     so a fleet of weekly domains spreads naturally across 7 days
 *     instead of hammering Monday morning.
 */
class DispatchScheduledScans extends Command
{
    /** @var string */
    protected $signature = 'scans:dispatch-scheduled';

    /** @var string */
    protected $description = 'Dispatch queued scans for every domain whose cadence is due today.';

    public function handle(ScanRunner $runner, SitemapDiscoveryService $discovery): int
    {
        $now = Date::now();
        $dispatched = 0;
        $skipped = 0;

        Domain::query()
            ->whereNot('scan_cadence', ScanCadence::Never->value)
            ->cursor()
            ->each(function (Domain $domain) use ($now, $runner, $discovery, &$dispatched, &$skipped): void {
                if (! $this->isDue($domain, $now)) {
                    $skipped++;

                    return;
                }

                $urls = $discovery->discover($domain);
                $runner->dispatch($domain, $urls, ScanTrigger::Scheduled);
                $dispatched++;
            });

        $this->components->info(sprintf('Dispatched %d scheduled scan(s); skipped %d.', $dispatched, $skipped));

        return self::SUCCESS;
    }

    private function isDue(Domain $domain, Carbon $now): bool
    {
        /** @var ScanCadence $cadence */
        $cadence = $domain->getAttribute('scan_cadence');
        $intervalDays = $cadence->intervalDays();
        $staggerWindow = $cadence->staggerWindow();

        /** @var Carbon|null $lastScanned */
        $lastScanned = $domain->getAttribute('last_scanned_at');

        if ($lastScanned === null) {
            // Never scanned → dispatch on the domain's assigned stagger slot.
            $domainSlot = crc32((string) $domain->getKey()) % $staggerWindow;
            $todaySlot = $now->dayOfYear % $staggerWindow;

            return $domainSlot === $todaySlot;
        }

        // Previously scanned → wait until the interval has elapsed since the
        // last scan. The stagger is already baked into when the first scan
        // happened, so subsequent scans inherit that spread naturally.
        $daysSince = (int) $lastScanned->startOfDay()->diffInDays($now->copy()->startOfDay());

        return $daysSince >= $intervalDays;
    }
}
