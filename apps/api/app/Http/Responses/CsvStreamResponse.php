<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a result-set as a CSV download. Encapsulates the fopen +
 * fputcsv + lazy-cursor pattern that fat export controllers used to
 * inline. Memory stays bounded regardless of result size because the
 * underlying query is iterated via {@see Builder::lazy()}.
 *
 * Callers describe the export as a column header list + a mapper that
 * turns a single Eloquent model into the row of strings to write —
 * nothing else.
 *
 * Headers (`Content-Type`, `Cache-Control: no-store`) are owned here so
 * every CSV download answers identically; operator integrations
 * (Excel, BI tools, eDiscovery) can rely on the wire format.
 */
final class CsvStreamResponse
{
    private const int LAZY_CHUNK = 500;

    /**
     * Stream `$query` to the client as CSV.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns  Header row, in order.
     * @param  Closure(TModel): list<string>  $rowMapper
     *                                                    Receives one row from the lazy cursor, returns the values to write.
     */
    public static function for(
        Builder $query,
        array $columns,
        Closure $rowMapper,
        string $filename,
    ): StreamedResponse {
        return response()->streamDownload(
            function () use ($query, $columns, $rowMapper): void {
                $handle = fopen('php://output', 'w');
                if ($handle === false) {
                    return;
                }

                fputcsv($handle, $columns);

                foreach ($query->lazy(self::LAZY_CHUNK) as $row) {
                    fputcsv($handle, $rowMapper($row));
                }

                fclose($handle);
            },
            $filename,
            [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ],
        );
    }
}
