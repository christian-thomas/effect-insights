<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\A11yImpact;
use App\Models\AccessibilityFinding;
use App\Models\AccessibilityScan;
use App\Models\Domain;
use App\Notifications\AccessibilityIssuesFound;
use App\Notifications\AccessibilityScanFailed;
use App\Services\Accessibility\AccessibilityRuleMuteResolver;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Dispatches alerts for accessibility scans. Two firing paths:
 *
 *   - {@see self::alertIfNeeded()} — after a completed scan, if any
 *     violation at-or-above the configured threshold exists. Default
 *     threshold is `serious` (so Critical or Serious findings fire;
 *     Moderate and Minor stay quiet). Override via
 *     `config('services.accessibility.alert_threshold')`.
 *   - {@see self::alertOnFailure()} — when the scan throws, fires a
 *     sibling {@see AccessibilityScanFailed} notification that mirrors
 *     the cookie scanner's failure-alert shape (so on-call gets a
 *     consistent reading experience between the two pipelines).
 *
 * Both paths dispatch to whichever channels are configured: Slack via
 * `services.slack.notifications.channel`, mail via the parent org's
 * `notification_email`. Either can be set independently; both off =
 * silent (intentional for local/CI).
 *
 * Non-final + readonly so PHPUnit can swap in a stub that throws,
 * matching {@see ScanFailureAlertService}.
 */
readonly class AccessibilityAlertService
{
    public function alertIfNeeded(AccessibilityScan $scan, Domain $domain): void
    {
        $total = (int) $scan->getAttribute('violations_total');

        if ($total === 0) {
            return;
        }

        // Drop muted findings before counting. We recompute the
        // by-impact map from the actual findings rows rather than
        // reading the stored aggregate, because muting is a runtime
        // resolution — operators can add/remove overrides without
        // re-running the scan.
        $effective = $this->effectiveByImpact($scan, $domain);
        $effectiveTotal = array_sum($effective);

        if ($effectiveTotal === 0) {
            return;
        }

        if (! $this->meetsThreshold($effective, $domain)) {
            return;
        }

        $this->dispatchToConfiguredChannels(
            new AccessibilityIssuesFound(
                scan: $scan,
                domain: $domain,
                byImpact: $effective,
                total: $effectiveTotal,
                detailUrl: $this->detailUrlFor($scan),
            ),
            $domain,
        );
    }

    /**
     * Build a fresh by-impact map from findings rows minus anything an
     * active override mutes. Doesn't touch the stored `violations_by_impact`
     * column — that stays the audit-time aggregate of what axe found.
     *
     * @return array<string, int>
     */
    private function effectiveByImpact(AccessibilityScan $scan, Domain $domain): array
    {
        $resolver = AccessibilityRuleMuteResolver::forOrganisation(
            (string) $domain->getAttribute('organisation_id'),
        );

        $byImpact = [
            A11yImpact::Critical->value => 0,
            A11yImpact::Serious->value => 0,
            A11yImpact::Moderate->value => 0,
            A11yImpact::Minor->value => 0,
        ];

        AccessibilityFinding::query()
            ->withoutGlobalScope('organisation')
            ->where('accessibility_scan_id', $scan->getKey())
            ->get(['rule_id', 'impact'])
            ->each(function ($finding) use ($resolver, $domain, &$byImpact): void {
                if ($resolver->isMutedForDomain((string) $finding->getAttribute('rule_id'), $domain)) {
                    return;
                }
                $impact = $finding->getAttribute('impact');
                $impactValue = $impact instanceof A11yImpact ? $impact->value : (string) $impact;
                if (isset($byImpact[$impactValue])) {
                    $byImpact[$impactValue]++;
                }
            });

        return $byImpact;
    }

    /**
     * @param  array{message: string, class: string}  $error
     */
    public function alertOnFailure(AccessibilityScan $scan, Domain $domain, array $error): void
    {
        $this->dispatchToConfiguredChannels(
            new AccessibilityScanFailed(
                scan: $scan,
                domain: $domain,
                errorMessage: $error['message'] ?? 'unknown error',
                errorClass: $error['class'] ?? 'Throwable',
                detailUrl: $this->detailUrlFor($scan),
            ),
            $domain,
        );
    }

    private function dispatchToConfiguredChannels(BaseNotification $notification, Domain $domain): void
    {
        $channel = (string) config('services.slack.notifications.channel', '');
        if ($channel !== '') {
            Notification::route('slack', $channel)->notify($notification);
        }

        $organisation = $domain->organisation()->withoutGlobalScope('organisation')->first();
        $emails = $organisation?->notificationEmails() ?? [];
        if ($emails !== []) {
            Notification::route('mail', $emails)->notify($notification);
        }
    }

    /**
     * @param  array<string, int>  $byImpact
     */
    private function meetsThreshold(array $byImpact, Domain $domain): bool
    {
        $threshold = $this->configuredThreshold($domain);

        foreach ($byImpact as $impactValue => $count) {
            if ($count <= 0) {
                continue;
            }

            $impact = A11yImpact::tryFrom((string) $impactValue);
            if ($impact !== null && $impact->isAtLeast($threshold)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Per-org override falls back to the global config setting, which
     * itself falls back to `Serious`. Allows different tenants to run
     * different alert policies on the same platform deploy — e.g. a
     * media client on `minor` and an internal-tools client on
     * `critical`.
     */
    private function configuredThreshold(Domain $domain): A11yImpact
    {
        $organisation = $domain->organisation()->withoutGlobalScope('organisation')->first();
        $perOrg = (string) ($organisation?->getAttribute('a11y_alert_threshold') ?? '');
        if ($perOrg !== '') {
            $candidate = A11yImpact::tryFrom($perOrg);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        $global = (string) config('services.accessibility.alert_threshold', A11yImpact::Serious->value);

        return A11yImpact::tryFrom($global) ?? A11yImpact::Serious;
    }

    private function detailUrlFor(AccessibilityScan $scan): string
    {
        $appUrl = (string) config('app.url', '');

        if ($appUrl === '') {
            return sprintf('/accessibility-scans/%s', $scan->getKey());
        }

        return sprintf('%s/accessibility-scans/%s', rtrim($appUrl, '/'), $scan->getKey());
    }
}
