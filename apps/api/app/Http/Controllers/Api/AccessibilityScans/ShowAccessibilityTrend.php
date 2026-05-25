<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AccessibilityScans;

use App\Enums\A11yImpact;
use App\Enums\ScanStatus;
use App\Http\Controllers\Controller;
use App\Models\AccessibilityScan;
use App\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-domain time-series of accessibility-scan results. One data point
 * per completed scan in the requested window, ordered oldest-first so
 * the admin renders a left-to-right trend chart without re-sorting.
 *
 * Uses the stored `violations_by_impact` aggregate per scan rather
 * than recomputing from findings rows — this endpoint is for trend
 * visualisation, not policy enforcement, so the audit-time snapshot
 * is the right reference (muting changes shouldn't retroactively
 * rewrite the past).
 */
class ShowAccessibilityTrend extends Controller
{
    public function __invoke(Request $request, Domain $domain): JsonResponse
    {
        $days = max(7, min(365, (int) $request->integer('days', 90)));
        $since = CarbonImmutable::now()->subDays($days);

        $scans = AccessibilityScan::query()
            ->where('domain_id', $domain->getKey())
            ->where('status', ScanStatus::Completed)
            ->where('finished_at', '>=', $since)
            ->oldest('finished_at')
            ->get(['id', 'finished_at', 'violations_total', 'violations_by_impact']);

        return response()->json([
            'data' => [
                'domainId' => (string) $domain->getKey(),
                'days' => $days,
                'points' => $scans->map(fn (AccessibilityScan $scan): array => [
                    'scanId' => (string) $scan->getKey(),
                    'finishedAt' => $scan->getAttribute('finished_at')?->toIso8601String(),
                    'total' => (int) $scan->getAttribute('violations_total'),
                    'byImpact' => $this->normaliseByImpact($scan->getAttribute('violations_by_impact')),
                ])->all(),
            ],
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function normaliseByImpact(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [];

        return [
            A11yImpact::Critical->value => (int) ($values[A11yImpact::Critical->value] ?? 0),
            A11yImpact::Serious->value => (int) ($values[A11yImpact::Serious->value] ?? 0),
            A11yImpact::Moderate->value => (int) ($values[A11yImpact::Moderate->value] ?? 0),
            A11yImpact::Minor->value => (int) ($values[A11yImpact::Minor->value] ?? 0),
        ];
    }
}
