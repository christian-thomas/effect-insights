<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\ConsentEvents;

use App\Http\Controllers\Controller;
use App\Models\ConsentEvent;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the tenant's consent log as CSV with the same filter surface
 * as {@see ListConsentEvents} (domain_id / anonymous_id / event_type /
 * from / to). No pagination — the export is a single response. Streamed
 * via chunked DB cursor so large logs don't load fully into PHP memory.
 *
 * Columns are stable wire-format: tools (Excel, BI, ediscovery) build
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

    public function __invoke(Request $request): StreamedResponse
    {
        $query = ConsentEvent::query()->latest('occurred_at');

        if ($request->filled('domain_id')) {
            $query->where('domain_id', $request->string('domain_id'));
        }

        if ($request->filled('anonymous_id')) {
            $query->where('anonymous_id', $request->string('anonymous_id'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->string('event_type'));
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->date('to'));
        }

        $filename = sprintf('consent-events-%s.csv', now()->format('Y-m-d-His'));

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, self::COLUMNS);

            foreach ($query->lazy(500) as $event) {
                fputcsv($handle, [
                    (string) $event->getAttribute('id'),
                    $this->iso($event->getAttribute('occurred_at')),
                    (string) $event->getAttribute('domain_id'),
                    (string) $event->getAttribute('organisation_id'),
                    (int) $event->getAttribute('banner_config_version'),
                    (string) $event->getAttribute('anonymous_id'),
                    $this->scalar($event->getAttribute('event_type')),
                    (string) ($event->getAttribute('country_code') ?? ''),
                    $this->jsonOrEmpty($event->getAttribute('categories')),
                    $this->jsonOrEmpty($event->getAttribute('gcm_signals')),
                    (string) $event->getAttribute('banner_text_hash'),
                    (string) $event->getAttribute('user_agent_hash'),
                    (string) $event->getAttribute('ip_hash'),
                    $this->iso($event->getAttribute('created_at')),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
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
