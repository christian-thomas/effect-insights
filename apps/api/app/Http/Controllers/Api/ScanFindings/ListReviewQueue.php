<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\ScanFindings;

use App\Http\Controllers\Controller;
use App\Services\ScanFindings\ScanFindingsReviewQueueService;
use App\Support\CurrentOrganisation;
use Illuminate\Http\JsonResponse;

/**
 * Lists cookies the scanner saw but the resolver couldn't categorise,
 * grouped by name so the operator triages a name once rather than a
 * finding at a time. Aggregation + dynamic re-resolution lives in
 * {@see ScanFindingsReviewQueueService}.
 */
class ListReviewQueue extends Controller
{
    public function __invoke(
        ScanFindingsReviewQueueService $queue,
        CurrentOrganisation $currentOrganisation,
    ): JsonResponse {
        return response()->json([
            'data' => $queue->forOrganisation($currentOrganisation->getOrFail()),
        ]);
    }
}
