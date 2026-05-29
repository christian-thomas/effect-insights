<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Scans;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScanResource;
use App\Models\Scan;
use App\Services\Scanner\ScanRunner;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-runs only the URLs from `scan.failed_urls` against the existing
 * scan. Same scan row, same dispatch flow — `urls_scanned` resumes
 * ticking up, findings merge with what's already there, and
 * `finaliseBatch` re-evaluates status when the new batch completes.
 */
class RetryFailedUrls extends Controller
{
    public function __invoke(Scan $scan, ScanRunner $runner): JsonResponse
    {
        $failed = (array) $scan->getAttribute('failed_urls');
        if ($failed === []) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'No failed URLs to retry.');
        }

        $runner->retryFailed($scan);

        return new JsonResponse(
            ['data' => ScanResource::make($scan->refresh())],
            Response::HTTP_ACCEPTED,
        );
    }
}
