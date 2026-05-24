<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AccessibilityScan;
use App\Services\Accessibility\AccessibilityScanRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Background runner for a queued {@see AccessibilityScan}. Mirror of
 * {@see RunScanJob} but pinned to its own Horizon queue so a pile-up on
 * one vertical can't starve the other (axe runs are ~3x longer than
 * cookie crawls).
 */
class RunAccessibilityScanJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $urls
     */
    public function __construct(
        public readonly string $scanId,
        public readonly array $urls,
    ) {
        // Dedicated `accessibility-scans` queue — see config/horizon.php
        // for the supervisor + the 600s timeout that lets a 50-URL crawl
        // complete inside the runner's per-page timeout budget.
        $this->onQueue('accessibility-scans');
    }

    public function handle(AccessibilityScanRunner $runner): void
    {
        /** @var AccessibilityScan|null $scan */
        $scan = AccessibilityScan::query()
            ->withoutGlobalScope('organisation')
            ->find($this->scanId);

        if ($scan === null) {
            return;
        }

        $runner->execute($scan, $this->urls);
    }
}
