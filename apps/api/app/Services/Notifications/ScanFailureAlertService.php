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
        $channel = (string) config('services.slack.notifications.channel', '');
        if ($channel === '') {
            return;
        }

        Notification::route('slack', $channel)
            ->notify(new ScanFailed(
                scan: $scan,
                domain: $domain,
                errorMessage: $error['message'] ?? 'unknown error',
                errorClass: $error['class'] ?? 'Throwable',
                detailUrl: $this->detailUrlFor($scan),
            ));
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
