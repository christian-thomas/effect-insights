<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\ConsentEvents;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsentEvents\ListConsentEventsRequest;
use App\Http\Responses\CsvStreamResponse;
use App\Models\ConsentEvent;
use App\Services\ConsentEvents\ConsentEventCsvExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the tenant's consent log as CSV with the same filter surface
 * as {@see ListConsentEvents} — both endpoints share
 * {@see ListConsentEventsRequest} for validation + filter composition.
 * No pagination on the export; streamed via lazy DB cursor so large
 * logs don't load fully into PHP memory.
 *
 * Column ordering + row mapping live in
 * {@see ConsentEventCsvExporter} so the wire format is testable in
 * isolation — operator integrations (Excel, BI, eDiscovery) build on
 * stable column names + order.
 */
class ExportConsentEventsCsv extends Controller
{
    public function __invoke(
        ListConsentEventsRequest $request,
        ConsentEventCsvExporter $exporter,
    ): StreamedResponse {
        return CsvStreamResponse::for(
            query: $request->applyFilters(ConsentEvent::query()),
            columns: $exporter->columns(),
            rowMapper: $exporter->mapRow(...),
            filename: sprintf('consent-events-%s.csv', now()->format('Y-m-d-His')),
        );
    }
}
