<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Scanner\ScanRunner;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Scans a chunk of URLs from a {@see \App\Models\Scan}. Dispatched as
 * part of a {@see \Illuminate\Bus\Batch} from {@see ScanRunner::dispatch}
 * — chunk size is controlled by `services.scanner.url_chunk_size` so
 * the operator sees `urls_scanned` tick up in `chunk_size` increments
 * while the worker still gets to reuse one browser instance per chunk
 * (vs. per-URL launches, which are ~1-2s of pure overhead).
 *
 * Per-job failures are tolerated (the batch is dispatched with
 * `allowFailures()`); finalisation reads `urls_scanned` vs
 * `urls_discovered` to decide whether the scan landed `completed` or
 * `failed` (partial).
 */
class ScanUrlsJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Overrides the Horizon supervisor's `tries=2`. We want a longer
     * ride-out window for transient infra failures (worker unreachable,
     * 5xx, network blip) — the worker now isolates per-URL failures so
     * a chunk-level throw genuinely means infra, not a single bad page.
     */
    public int $tries = 4;

    /**
     * @param  list<string>  $urls
     */
    public function __construct(
        public readonly string $scanId,
        public readonly array $urls,
    ) {
        // Pinned to the `scans` queue — Horizon `supervisor-scans` owns
        // worker concurrency for the vertical.
        $this->onQueue('scans');
    }

    /**
     * Exponential backoff between retries: 30s, 2min, 5min. Total budget
     * ~7-8 minutes — long enough to clear a CF Browser Rendering quota
     * blip without re-running stale work.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ScanRunner $runner): void
    {
        // Operator cancelled the batch (or it was force-finalised):
        // short-circuit so we don't keep hammering the worker.
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $runner->scanUrlChunk($this->scanId, $this->urls);
    }

    /**
     * Called by Laravel after all retry attempts are exhausted. Records
     * the chunk's URLs on the scan's failed_urls column so the operator
     * can retry just the gaps via the "Retry failed URLs" admin button.
     */
    public function failed(Throwable $exception): void
    {
        app(ScanRunner::class)->recordChunkFailure($this->scanId, $this->urls, $exception);
    }
}
