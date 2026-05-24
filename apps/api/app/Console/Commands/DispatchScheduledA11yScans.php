<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ScanCadence;
use App\Enums\ScanTrigger;
use App\Models\Domain;
use App\Services\Accessibility\AccessibilityScanRunner;
use App\Services\Scanner\SitemapDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Daily entry point for scheduled accessibility scans. Mirrors
 * {@see DispatchScheduledScans} exactly — same stagger algorithm,
 * same "due today" check — but reads `a11y_scan_cadence` /
 * `last_a11y_scanned_at` and dispatches via the accessibility
 * runner instead.
 */
class DispatchScheduledA11yScans extends Command
{
    /** @var string */
    protected $signature = 'accessibility:dispatch-scheduled';

    /** @var string */
    protected $description = 'Dispatch queued accessibility scans for every domain whose a11y cadence is due today.';

    public function handle(
        AccessibilityScanRunner $runner,
        SitemapDiscoveryService $discovery,
    ): int {
        $now = Date::now();
        $dispatched = 0;
        $skipped = 0;

        Domain::query()
            ->whereNot('a11y_scan_cadence', ScanCadence::Never->value)
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

        $this->components->info(sprintf(
            'Dispatched %d scheduled accessibility scan(s); skipped %d.',
            $dispatched,
            $skipped,
        ));

        return self::SUCCESS;
    }

    private function isDue(Domain $domain, Carbon $now): bool
    {
        /** @var ScanCadence $cadence */
        $cadence = $domain->getAttribute('a11y_scan_cadence');
        $intervalDays = $cadence->intervalDays();
        $staggerWindow = $cadence->staggerWindow();

        /** @var Carbon|null $lastScanned */
        $lastScanned = $domain->getAttribute('last_a11y_scanned_at');

        if ($lastScanned === null) {
            $domainSlot = crc32((string) $domain->getKey()) % $staggerWindow;
            $todaySlot = $now->dayOfYear % $staggerWindow;

            return $domainSlot === $todaySlot;
        }

        $daysSince = (int) $lastScanned->startOfDay()->diffInDays($now->copy()->startOfDay());

        return $daysSince >= $intervalDays;
    }
}
