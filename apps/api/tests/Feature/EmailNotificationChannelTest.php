<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanTrigger;
use App\Models\Domain;
use App\Notifications\AccessibilityIssuesFound;
use App\Notifications\AccessibilityScanFailed;
use App\Notifications\ScanFailed;
use App\Notifications\UnknownCookieFound;
use App\Services\Accessibility\AccessibilityScannerClientContract;
use App\Services\Accessibility\AccessibilityScannerResult;
use App\Services\Accessibility\AccessibilityScanRunner;
use App\Services\Scanner\ScannerResult;
use App\Services\Scanner\ScanRunner;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TenantTestCase;

/**
 * Covers the mail channel: each of the four notification types fires to
 * the parent org's `notification_email` recipients in addition to Slack.
 */
class EmailNotificationChannelTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.slack.notifications.channel', '#cmp-ops');
        Notification::fake();

        $this->org->update(['notification_email' => 'alerts@example.test, ops@example.test']);
        $this->domain = Domain::factory()->for($this->org)->create(['fqdn' => 'site.example.test']);
    }

    #[Test]
    public function organisation_splits_comma_separated_emails(): void
    {
        $this->assertSame(
            ['alerts@example.test', 'ops@example.test'],
            $this->org->fresh()?->notificationEmails(),
        );
    }

    #[Test]
    public function unknown_cookie_alert_fires_to_both_slack_and_mail(): void
    {
        // Seed a canonical cookie so the resolver doesn't match the
        // mystery one.
        \App\Models\Cookie::create([
            'name_pattern' => '_known_*',
            'vendor' => 'Known',
            'category' => \App\Enums\CookieCategory::Statistics,
            'purpose' => 'p',
            'source' => \App\Enums\CookieSource::Manual,
        ]);

        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            cookies: [
                ['name' => '_mystery_one', 'domain' => '.tracker.test', 'party' => 'third'],
            ],
            scripts: [], iframes: [], requests: [],
        ));

        $this->app->make(ScanRunner::class)
            ->run($this->domain, ['https://site.example.test/'], ScanTrigger::Manual);

        // Slack: routed by channel name
        Notification::assertSentOnDemand(
            UnknownCookieFound::class,
            function (UnknownCookieFound $notification, array $channels, AnonymousNotifiable $notifiable): bool {
                return ($notifiable->routes['slack'] ?? null) === '#cmp-ops';
            },
        );

        // Mail: routed to the org's two recipients as an array
        Notification::assertSentOnDemand(
            UnknownCookieFound::class,
            function (UnknownCookieFound $notification, array $channels, AnonymousNotifiable $notifiable): bool {
                $mailRoute = $notifiable->routes['mail'] ?? null;
                return is_array($mailRoute) && in_array('alerts@example.test', $mailRoute, true);
            },
        );
    }

    #[Test]
    public function scan_failure_alert_fires_to_both_channels(): void
    {
        $failing = new class implements \App\Services\Scanner\ScannerClientContract
        {
            #[\Override]
            public function scan(Domain $domain, array $urls): ScannerResult
            {
                throw new RuntimeException('boom');
            }
        };
        $this->app->instance(\App\Services\Scanner\ScannerClientContract::class, $failing);

        $this->app->make(ScanRunner::class)
            ->run($this->domain, ['https://site.example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(ScanFailed::class, function (
            ScanFailed $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ): bool {
            return ($notifiable->routes['slack'] ?? null) === '#cmp-ops';
        });
        Notification::assertSentOnDemand(ScanFailed::class, function (
            ScanFailed $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ): bool {
            $mailRoute = $notifiable->routes['mail'] ?? null;
            return is_array($mailRoute) && in_array('ops@example.test', $mailRoute, true);
        });
    }

    #[Test]
    public function accessibility_issues_alert_fires_to_both_channels(): void
    {
        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            findings: [
                [
                    'rule_id' => 'image-alt',
                    'impact' => 'critical',
                    'description' => 'd', 'help_text' => 'h', 'help_url' => 'https://example.test/h',
                    'url' => 'https://site.example.test/', 'tags' => [], 'nodes' => [],
                ],
            ],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://site.example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(AccessibilityIssuesFound::class, function (
            AccessibilityIssuesFound $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ): bool {
            return ($notifiable->routes['slack'] ?? null) === '#cmp-ops';
        });
        Notification::assertSentOnDemand(AccessibilityIssuesFound::class, function (
            AccessibilityIssuesFound $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ): bool {
            $mailRoute = $notifiable->routes['mail'] ?? null;
            return is_array($mailRoute) && count($mailRoute) === 2;
        });
    }

    #[Test]
    public function accessibility_scan_failure_alert_fires_to_both_channels(): void
    {
        $failing = new class implements AccessibilityScannerClientContract
        {
            #[\Override]
            public function scan(Domain $domain, array $urls): AccessibilityScannerResult
            {
                throw new RuntimeException('boom');
            }
        };
        $this->app->instance(AccessibilityScannerClientContract::class, $failing);

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://site.example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(AccessibilityScanFailed::class, function (
            AccessibilityScanFailed $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ): bool {
            $mailRoute = $notifiable->routes['mail'] ?? null;
            return is_array($mailRoute) && in_array('alerts@example.test', $mailRoute, true);
        });
    }

    #[Test]
    public function no_email_recipients_silently_skips_mail_channel(): void
    {
        $this->org->update(['notification_email' => null]);

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            findings: [
                [
                    'rule_id' => 'image-alt',
                    'impact' => 'critical',
                    'description' => 'd', 'help_text' => 'h', 'help_url' => 'https://example.test/h',
                    'url' => 'https://site.example.test/', 'tags' => [], 'nodes' => [],
                ],
            ],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://site.example.test/'], ScanTrigger::Manual);

        // Slack still fires
        Notification::assertSentOnDemand(AccessibilityIssuesFound::class, function (
            AccessibilityIssuesFound $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ): bool {
            return ($notifiable->routes['slack'] ?? null) === '#cmp-ops';
        });

        // But no mail-route fired
        Notification::assertSentOnDemandTimes(AccessibilityIssuesFound::class, 1);
    }

    #[Test]
    public function malformed_email_entries_are_filtered_out(): void
    {
        $this->org->update(['notification_email' => 'good@example.test, , not-an-email, also-good@example.test']);

        $this->assertSame(
            ['good@example.test', 'also-good@example.test'],
            $this->org->fresh()?->notificationEmails(),
        );
    }
}
