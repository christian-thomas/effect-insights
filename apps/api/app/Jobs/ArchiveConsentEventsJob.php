<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ConsentArchive\ArchiveConsentEventsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Nightly archive sweep — drains D1 rows older than the retention window
 * into MySQL `consent_events`. Scheduled via routes/console.php.
 *
 * Stays on the `default` queue (60s/3-tries supervisor); a typical run
 * handles a few thousand rows in a few seconds via batched D1 reads.
 */
class ArchiveConsentEventsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $retentionDays = ArchiveConsentEventsService::DEFAULT_RETENTION_DAYS,
        public readonly int $batchSize = ArchiveConsentEventsService::DEFAULT_BATCH_SIZE,
    ) {}

    public function handle(ArchiveConsentEventsService $service): void
    {
        $result = $service->archive($this->retentionDays, $this->batchSize);

        Log::info('consent-events archive run complete', [
            'cutoff' => $result->cutoff,
            'upserted' => $result->upserted,
            'deleted' => $result->deleted,
            'skipped' => $result->skipped,
        ]);
    }
}
