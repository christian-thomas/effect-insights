<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AccessibilityScans;

use App\Enums\A11yImpact;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccessibilityScanResource;
use App\Models\AccessibilityFinding;
use App\Models\AccessibilityScan;
use App\Services\Accessibility\AccessibilityRuleMuteResolver;
use Illuminate\Http\JsonResponse;

/**
 * Returns the scan envelope + every finding it produced. Findings come
 * back ordered by impact severity (Critical → Minor) so the admin
 * never has to re-sort client-side.
 *
 * Each finding carries a computed `muted` flag derived at request time
 * from the org's {@see \App\Models\AccessibilityRuleOverride} rows.
 * Mute resolution is dynamic — operators can mute/unmute without
 * re-running the scan, and the next list/show reflects it.
 */
class ShowAccessibilityScan extends Controller
{
    public function __invoke(AccessibilityScan $accessibilityScan): JsonResponse
    {
        // Order findings most-severe-first so the admin renders the
        // important stuff at the top of every group without extra
        // logic. CASE WHEN works in both MySQL and SQLite (used in
        // tests). FIELD() would be MySQL-only.
        $impactOrder = sprintf(
            "CASE impact WHEN '%s' THEN 1 WHEN '%s' THEN 2 WHEN '%s' THEN 3 WHEN '%s' THEN 4 ELSE 5 END",
            A11yImpact::Critical->value,
            A11yImpact::Serious->value,
            A11yImpact::Moderate->value,
            A11yImpact::Minor->value,
        );

        /** @var \Illuminate\Database\Eloquent\Collection<int, AccessibilityFinding> $findings */
        $findings = $accessibilityScan->findings()
            ->orderByRaw($impactOrder)
            ->orderBy('rule_id')
            ->orderBy('url')
            ->get();

        $resolver = AccessibilityRuleMuteResolver::forOrganisation(
            (string) $accessibilityScan->getAttribute('organisation_id'),
        );
        $domainId = (string) $accessibilityScan->getAttribute('domain_id');

        // Pre-compute the mute flag once per finding and attach it as
        // a non-DB attribute so AccessibilityFindingResource can emit it.
        $findings->each(function (AccessibilityFinding $finding) use ($resolver, $domainId): void {
            $finding->setAttribute(
                'muted',
                $resolver->isMuted((string) $finding->getAttribute('rule_id'), $domainId),
            );
        });

        return response()->json([
            'scan' => AccessibilityScanResource::make($accessibilityScan),
            'findings' => \App\Http\Resources\AccessibilityFindingResource::collection($findings),
        ]);
    }
}
