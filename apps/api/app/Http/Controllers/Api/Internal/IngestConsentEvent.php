<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Actions\ConsentEvents\IngestConsentEvent as IngestConsentEventAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConsentEventRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirror endpoint for the edge consent worker. The worker writes the
 * canonical row to D1 first and fires this off as a best-effort
 * fire-and-forget — Laravel's copy is what powers the consent log
 * viewer and long-term audit (>90 days, past D1's hot window).
 *
 * Idempotent — receiving the same UUID twice is a no-op.
 */
class IngestConsentEvent extends Controller
{
    public function __invoke(StoreConsentEventRequest $request, IngestConsentEventAction $ingest): JsonResponse
    {
        $ingest($request->validated());

        return response()->json(['ok' => true], Response::HTTP_ACCEPTED);
    }
}
