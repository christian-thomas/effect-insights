<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\FindingType;
use App\Models\Domain;
use App\Models\Scan;
use App\Models\ScanFinding;
use App\Notifications\UnknownCookieFound;
use Illuminate\Support\Facades\Notification;

/**
 * Inspects a freshly-completed scan for findings the resolver couldn't
 * categorise (`category IS NULL`) and dispatches a single Slack alert
 * if any exist.
 *
 * Routes via Laravel's notification system —
 * {@see UnknownCookieFound} defines the message.
 * Tests use `Notification::fake()`. No-ops silently when the Slack
 * channel isn't configured (local dev / CI stays quiet).
 */
final readonly class UnknownCookieAlertService
{
    public function alertIfNeeded(Scan $scan, Domain $domain): void
    {
        $unknownNames = $this->collectUnknownCookieNames($scan);

        if ($unknownNames === []) {
            return;
        }

        $notification = new UnknownCookieFound(
            scan: $scan,
            domain: $domain,
            unknownCookieNames: $unknownNames,
            reviewUrl: $this->reviewUrlFor($scan),
        );

        $channel = (string) config('services.slack.notifications.channel', '');
        if ($channel !== '') {
            Notification::route('slack', $channel)->notify($notification);
        }

        $emails = $this->emailsFor($domain);
        if ($emails !== []) {
            Notification::route('mail', $emails)->notify($notification);
        }
    }

    /**
     * @return array<int, string>
     */
    private function emailsFor(Domain $domain): array
    {
        $organisation = $domain->organisation()->withoutGlobalScope('organisation')->first();

        return $organisation?->notificationEmails() ?? [];
    }

    /**
     * @return array<int, string>
     */
    private function collectUnknownCookieNames(Scan $scan): array
    {
        return ScanFinding::query()
            ->withoutGlobalScope('organisation')
            ->where('scan_id', $scan->getKey())
            ->where('type', FindingType::Cookie->value)
            ->whereNull('category')
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->unique()
            ->values()
            ->all();
    }

    private function reviewUrlFor(Scan $scan): string
    {
        $appUrl = (string) config('app.url', '');

        if ($appUrl === '') {
            return '/cookies/review';
        }

        return sprintf('%s/cookies/review?scan_id=%s', rtrim($appUrl, '/'), $scan->getKey());
    }
}
