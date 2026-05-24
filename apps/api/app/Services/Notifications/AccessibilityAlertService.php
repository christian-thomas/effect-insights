<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\A11yImpact;
use App\Models\AccessibilityScan;
use App\Models\Domain;
use App\Notifications\AccessibilityIssuesFound;
use App\Notifications\AccessibilityScanFailed;
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
        $byImpact = (array) $scan->getAttribute('violations_by_impact');
        $total = (int) $scan->getAttribute('violations_total');

        if ($total === 0) {
            return;
        }

        if (! $this->meetsThreshold($byImpact)) {
            return;
        }

        $this->dispatchToConfiguredChannels(
            new AccessibilityIssuesFound(
                scan: $scan,
                domain: $domain,
                byImpact: $byImpact,
                total: $total,
                detailUrl: $this->detailUrlFor($scan),
            ),
            $domain,
        );
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
    private function meetsThreshold(array $byImpact): bool
    {
        $threshold = $this->configuredThreshold();

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

    private function configuredThreshold(): A11yImpact
    {
        $raw = (string) config('services.accessibility.alert_threshold', A11yImpact::Serious->value);

        return A11yImpact::tryFrom($raw) ?? A11yImpact::Serious;
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
