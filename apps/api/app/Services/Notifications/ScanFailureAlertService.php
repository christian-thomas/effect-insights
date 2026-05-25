<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Domain;
use App\Models\Scan;
use App\Notifications\ScanFailed;
use Illuminate\Support\Facades\Notification;

/**
 * Dispatches a Slack alert when a scan transitions to ScanStatus::Failed.
 * No-ops silently when services.slack.notifications.channel isn't set,
 * matching the {@see UnknownCookieAlertService} pattern so local + CI
 * runs stay quiet.
 */
// Non-final so tests can swap in a stub that throws to exercise the
// "alerter blowing up shouldn't mask the original failure" guarantee.
readonly class ScanFailureAlertService
{
    /**
     * @param  array{message: string, class: string}  $error
     */
    public function alert(Scan $scan, Domain $domain, array $error): void
    {
        $notification = new ScanFailed(
            scan: $scan,
            domain: $domain,
            errorMessage: $error['message'],
            errorClass: $error['class'],
            detailUrl: $this->detailUrlFor($scan),
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

    private function detailUrlFor(Scan $scan): string
    {
        $appUrl = (string) config('app.url', '');

        if ($appUrl === '') {
            return sprintf('/scans/%s', $scan->getKey());
        }

        return sprintf('%s/scans/%s', rtrim($appUrl, '/'), $scan->getKey());
    }
}
