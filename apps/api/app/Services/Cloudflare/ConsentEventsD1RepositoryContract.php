<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

/**
 * Narrow contract over Cloudflare D1 for the consent-events archive job.
 *
 * Two operations are all the archive needs:
 *
 *   - {@see self::fetchBefore()}  Pull a batch of rows older than the
 *                                  cutoff timestamp.
 *   - {@see self::deleteByIds()}  Drop them once they've been safely
 *                                  upserted into MySQL.
 *
 * Keeping it specific to this use case (rather than exposing raw
 * query/execute) means {@see ArrayConsentEventsD1Repository} is a few
 * lines of array filtering instead of an SQL parser.
 */
interface ConsentEventsD1RepositoryContract
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchBefore(string $cutoffIso, int $limit): array;

    /**
     * @param  array<int, string>  $ids
     */
    public function deleteByIds(array $ids): void;
}
