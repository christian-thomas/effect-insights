<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Accessibility\AccessibilityScanRunner;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Scans a chunk of URLs from an {@see \App\Models\AccessibilityScan}.
 * Mirror of {@see ScanUrlsJob} for the axe-core pipeline — chunk size
 * is shared via `services.scanner.url_chunk_size` so cookie + a11y
 * scans use the same operator-visible knob.
 *
 * Per-job failures are tolerated (the batch is dispatched with
 * `allowFailures()`); finalisation reads `urls_scanned` vs
 * `urls_discovered` and recounts violations from the persisted
 * findings to decide whether the scan landed `completed` or `failed`.
 */
class ScanAccessibilityUrlsJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Overrides the Horizon supervisor's `tries=2`. Same shape as
     * {@see ScanUrlsJob}: per-URL worker failures don't trigger the
     * chunk-level retry anymore, so any retry here is a real infra
     * problem worth backing off through.
     */
    public int $tries = 4;

    /**
     * @param  list<string>  $urls
     */
    public function __construct(
        public readonly string $scanId,
        public readonly array $urls,
    ) {
        // Dedicated queue so an a11y pile-up can't starve cookie scans.
        // See config/horizon.php → supervisor-accessibility-scans.
        $this->onQueue('accessibility-scans');
    }

    /**
     * 30s → 2min → 5min between attempts; matches {@see ScanUrlsJob}.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(AccessibilityScanRunner $runner): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $runner->scanUrlChunk($this->scanId, $this->urls);
    }

    /**
     * Called by Laravel after all retry attempts are exhausted. Records
     * the chunk's URLs on the scan's failed_urls column.
     */
    public function failed(Throwable $exception): void
    {
        app(AccessibilityScanRunner::class)->recordChunkFailure($this->scanId, $this->urls, $exception);
    }
}
