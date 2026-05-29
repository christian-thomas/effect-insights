<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AccessibilityScans;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessibilityScanResource;
use App\Models\AccessibilityScan;
use App\Services\Accessibility\AccessibilityScanRunner;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirror of {@see \App\Http\Controllers\Api\Scans\RetryFailedUrls} for
 * the accessibility scanner. Re-dispatches a chunked batch over the
 * scan's `failed_urls` list against the existing scan row.
 */
class RetryFailedAccessibilityUrls extends Controller
{
    public function __invoke(AccessibilityScan $accessibilityScan, AccessibilityScanRunner $runner): JsonResponse
    {
        $failed = (array) $accessibilityScan->getAttribute('failed_urls');
        if ($failed === []) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'No failed URLs to retry.');
        }

        $runner->retryFailed($accessibilityScan);

        return new JsonResponse(
            ['data' => AccessibilityScanResource::make($accessibilityScan->refresh())],
            Response::HTTP_ACCEPTED,
        );
    }
}
