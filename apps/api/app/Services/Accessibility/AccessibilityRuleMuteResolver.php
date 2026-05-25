<?php

declare(strict_types=1);

namespace App\Services\Accessibility;

use App\Http\Controllers\Api\AccessibilityScans\ShowAccessibilityScan;
use App\Models\AccessibilityRuleOverride;
use App\Models\Domain;
use App\Services\Notifications\AccessibilityAlertService;

/**
 * Checks whether a given (rule_id, domain) pair is muted by a current
 * override row. Used in two places:
 *
 * - {@see AccessibilityAlertService} — to
 *   skip muted findings when computing the alert threshold count.
 * - {@see ShowAccessibilityScan}
 *   — to mark `muted: true` on each rendered finding so the admin UI
 *   can dim them.
 *
 * Loads all of an organisation's overrides once via
 * {@see self::forOrganisation()} and reuses the result for many
 * lookups — avoids N+1 across a scan's findings.
 */
final readonly class AccessibilityRuleMuteResolver
{
    public static function forOrganisation(string $organisationId): self
    {
        $overrides = AccessibilityRuleOverride::query()
            ->withoutGlobalScope('organisation')
            ->where('organisation_id', $organisationId)
            ->get(['rule_id', 'domain_id']);

        $orgWide = [];
        $byDomain = [];
        foreach ($overrides as $override) {
            $ruleId = (string) $override->getAttribute('rule_id');
            $domainId = $override->getAttribute('domain_id');
            if ($domainId === null) {
                $orgWide[$ruleId] = true;
            } else {
                $byDomain[(string) $domainId][$ruleId] = true;
            }
        }

        return new self($orgWide, $byDomain);
    }

    /**
     * @param  array<string, true>  $orgWide  rule_id => true
     * @param  array<string, array<string, true>>  $byDomain  domain_id => rule_id => true
     */
    public function __construct(
        private array $orgWide,
        private array $byDomain,
    ) {}

    public function isMuted(string $ruleId, ?string $domainId): bool
    {
        if (isset($this->orgWide[$ruleId])) {
            return true;
        }

        return $domainId !== null && isset($this->byDomain[$domainId][$ruleId]);
    }

    public function isMutedForDomain(string $ruleId, Domain $domain): bool
    {
        return $this->isMuted($ruleId, (string) $domain->getKey());
    }
}
