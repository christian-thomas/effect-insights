<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Scan;
use App\Services\Scanner\ScanRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Background runner for a queued {@see Scan}. Loaded by ID rather than
 * via SerializesModels so we always pick up the freshest row state when
 * the worker drains the queue.
 *
 * Failure of the inner ScanRunner::execute is captured into the Scan
 * row (status=failed, error_log populated) — the job itself completes
 * successfully so the queue doesn't retry forever.
 */
class RunScanJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $urls
     */
    public function __construct(
        public readonly string $scanId,
        public readonly array $urls,
    ) {
        // Pin to the `scans` queue so a long-running crawl doesn't starve the
        // default workers. See config/horizon.php → supervisor-scans.
        $this->onQueue('scans');
    }

    public function handle(ScanRunner $runner): void
    {
        /** @var Scan|null $scan */
        $scan = Scan::query()
            ->withoutGlobalScope('organisation')
            ->find($this->scanId);

        if ($scan === null) {
            // Scan was deleted between dispatch and pickup — nothing to do.
            return;
        }

        $runner->execute($scan, $this->urls);
    }
}
