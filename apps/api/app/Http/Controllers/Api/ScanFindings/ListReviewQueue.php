<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\ScanFindings;

use App\Enums\FindingType;
use App\Http\Controllers\Controller;
use App\Models\ScanFinding;
use App\Services\Cookies\CookieCategoryResolver;
use App\Support\Cookies\CookieResolution;
use App\Support\CurrentOrganisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lists cookies the scanner saw but the resolver couldn't categorise,
 * grouped by name so the operator triages a name once rather than a
 * finding at a time.
 *
 * Filters out names that the resolver *would* categorise now — this is
 * how the queue shrinks when an operator creates an override: the next
 * GET excludes that name even though the underlying scan_findings row
 * still carries category=NULL.
 */
class ListReviewQueue extends Controller
{
    public function __invoke(
        Request $request,
        CookieCategoryResolver $resolver,
        CurrentOrganisation $currentOrganisation,
    ): JsonResponse {
        // Tenant scope is already on ScanFinding — but we use the resolver
        // below which expects an Organisation, so resolve it once here.
        $organisation = $currentOrganisation->getOrFail();

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
            $resolution = $resolver->resolve($name, organisation: $organisation);
            if ($resolution instanceof CookieResolution) {
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

        return response()->json(['data' => $items]);
    }
}
