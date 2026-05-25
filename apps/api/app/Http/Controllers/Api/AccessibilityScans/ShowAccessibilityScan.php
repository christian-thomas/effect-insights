<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AccessibilityScans;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessibilityFindingResource;
use App\Http\Resources\AccessibilityScanResource;
use App\Models\AccessibilityRuleOverride;
use App\Models\AccessibilityScan;
use App\Services\Accessibility\AccessibilityRuleMuteResolver;
use Illuminate\Http\JsonResponse;

/**
 * Returns the scan envelope + every finding it produced. Findings come
 * back ordered by impact severity (Critical → Minor) via the
 * `orderByImpact` query scope.
 *
 * Each finding carries a computed `muted` flag derived at request time
 * from the org's {@see AccessibilityRuleOverride} rows — mute
 * resolution is dynamic, so operators can mute/unmute without
 * re-running the scan.
 */
class ShowAccessibilityScan extends Controller
{
    public function __invoke(AccessibilityScan $accessibilityScan): JsonResponse
    {
        $findings = $accessibilityScan->findings()
            ->orderByImpact()
            ->orderBy('rule_id')
            ->orderBy('url')
            ->get();

        AccessibilityRuleMuteResolver::forOrganisation(
            (string) $accessibilityScan->getAttribute('organisation_id'),
        )->muteFindings($findings, (string) $accessibilityScan->getAttribute('domain_id'));

        return new JsonResponse([
            'scan' => AccessibilityScanResource::make($accessibilityScan),
            'findings' => AccessibilityFindingResource::collection($findings),
        ]);
    }
}
