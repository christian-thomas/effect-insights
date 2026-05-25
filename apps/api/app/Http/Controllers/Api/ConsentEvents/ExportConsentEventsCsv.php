<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\ConsentEvents;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsentEvents\ListConsentEventsRequest;
use App\Http\Responses\CsvStreamResponse;
use App\Models\ConsentEvent;
use BackedEnum;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the tenant's consent log as CSV with the same filter surface
 * as {@see ListConsentEvents} — both endpoints share
 * {@see ListConsentEventsRequest} for validation + filter composition.
 * No pagination on the export; streamed via lazy DB cursor so large
 * logs don't load fully into PHP memory.
 *
 * Columns are stable wire-format: tools (Excel, BI, eDiscovery) build
 * on these names. JSON columns (categories, gcm_signals) flatten to
 * one column each via JSON encoding so the CSV stays grid-friendly.
 */
class ExportConsentEventsCsv extends Controller
{
    private const array COLUMNS = [
        'id',
        'occurred_at',
        'domain_id',
        'organisation_id',
        'banner_config_version',
        'anonymous_id',
        'event_type',
        'country_code',
        'categories',
        'gcm_signals',
        'banner_text_hash',
        'user_agent_hash',
        'ip_hash',
        'created_at',
    ];

    public function __invoke(ListConsentEventsRequest $request): StreamedResponse
    {
        return CsvStreamResponse::for(
            query: $request->applyFilters(ConsentEvent::query()),
            columns: self::COLUMNS,
            rowMapper: fn (ConsentEvent $event): array => [
                (string) $event->getAttribute('id'),
                $this->iso($event->getAttribute('occurred_at')),
                (string) $event->getAttribute('domain_id'),
                (string) $event->getAttribute('organisation_id'),
                (string) (int) $event->getAttribute('banner_config_version'),
                (string) $event->getAttribute('anonymous_id'),
                $this->scalar($event->getAttribute('event_type')),
                (string) ($event->getAttribute('country_code') ?? ''),
                $this->jsonOrEmpty($event->getAttribute('categories')),
                $this->jsonOrEmpty($event->getAttribute('gcm_signals')),
                (string) $event->getAttribute('banner_text_hash'),
                (string) $event->getAttribute('user_agent_hash'),
                (string) $event->getAttribute('ip_hash'),
                $this->iso($event->getAttribute('created_at')),
            ],
            filename: sprintf('consent-events-%s.csv', now()->format('Y-m-d-His')),
        );
    }

    private function iso(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return $value === null ? '' : (string) $value;
    }

    private function scalar(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value === null ? '' : (string) $value;
    }

    private function jsonOrEmpty(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : $encoded;
    }
}
