<?php

declare(strict_types=1);

namespace App\Enums;

enum ScanStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    // Some URLs scanned, some failed irrecoverably (worker per-URL
    // errors or chunk-job retry exhaustion). Findings from the
    // successful URLs are persisted; failed URLs land on the scan's
    // `failed_urls` column for the "Retry failed URLs" admin flow.
    case Partial = 'partial';
    // No URLs scanned at all — total failure of the run.
    case Failed = 'failed';
}
