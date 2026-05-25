<?php

declare(strict_types=1);

namespace App\Services\ScanFindings;

use App\Enums\FindingType;
use App\Models\Organisation;
use App\Models\ScanFinding;
use App\Services\Cookies\CookieCategoryResolver;
use App\Support\Cookies\CookieResolution;

/**
 * Aggregates uncategorised cookie scan-findings into a triage queue
 * grouped by cookie name. Filters out names that the resolver *would*
 * categorise now — that's how the queue shrinks when an operator
 * creates an override: the resolver picks the new override up and the
 * row drops out, even though the underlying scan_findings row still
 * carries category=NULL.
 */
final readonly class ScanFindingsReviewQueueService
{
    public function __construct(
        private CookieCategoryResolver $resolver,
    ) {}

    /**
     * @return list<array{
     *     name: string,
     *     occurrences: int,
     *     scanCount: int,
     *     firstSeenUrl: ?string,
     *     firstSeenAt: ?string,
     *     lastSeenAt: ?string,
     * }>
     */
    public function forOrganisation(Organisation $organisation): array
    {
        $rows = ScanFinding::query()
            ->where('type', FindingType::Cookie->value)
            ->whereNull('category')
            ->selectRaw('name, COUNT(*) AS occurrences, MIN(first_seen_url) AS first_seen_url, MIN(created_at) AS first_seen_at, MAX(created_at) AS last_seen_at, COUNT(DISTINCT scan_id) AS scan_count')
            ->groupBy('name')
            ->latest('last_seen_at')
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $name = (string) $row->getAttribute('name');
            if ($name === '') {
                continue;
            }

            // Re-resolve with the current org context — picks up any
            // override the operator added since the original scan.
            if ($this->resolver->resolve($name, organisation: $organisation) instanceof CookieResolution) {
                continue;
            }

            $items[] = [
                'name' => $name,
                'occurrences' => (int) $row->getAttribute('occurrences'),
                'scanCount' => (int) $row->getAttribute('scan_count'),
                'firstSeenUrl' => $row->getAttribute('first_seen_url'),
                'firstSeenAt' => $row->getAttribute('first_seen_at'),
                'lastSeenAt' => $row->getAttribute('last_seen_at'),
            ];
        }

        return $items;
    }
}
