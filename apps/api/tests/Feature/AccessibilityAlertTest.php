<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanTrigger;
use App\Models\Domain;
use App\Notifications\AccessibilityIssuesFound;
use App\Notifications\AccessibilityScanFailed;
use App\Services\Accessibility\AccessibilityScannerClientContract;
use App\Services\Accessibility\AccessibilityScannerResult;
use App\Services\Accessibility\AccessibilityScanRunner;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TenantTestCase;

class AccessibilityAlertTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.slack.notifications.channel', '#cmp-ops');
        Config::set('services.slack.notifications.bot_user_oauth_token', 'xoxb-test-token');
        Config::set('services.accessibility.alert_threshold', 'serious');

        Notification::fake();

        $this->domain = Domain::factory()->for($this->org)->create(['fqdn' => 'example.test']);
    }

    private function findingWith(string $impact, string $ruleId = 'color-contrast'): array
    {
        return [
            'rule_id' => $ruleId,
            'impact' => $impact,
            'description' => 'desc',
            'help_text' => 'help',
            'help_url' => 'https://example.test/help',
            'url' => 'https://example.test/',
            'tags' => [],
            'nodes' => [],
        ];
    }

    #[Test]
    public function alerts_when_a_critical_violation_is_found(): void
    {
        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            findings: [$this->findingWith('critical', 'image-alt')],
        ));

        $scan = $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(
            AccessibilityIssuesFound::class,
            function (AccessibilityIssuesFound $notification, array $channels, AnonymousNotifiable $notifiable) use ($scan): bool {
                $this->assertSame(['slack'], $channels);
                $this->assertSame('#cmp-ops', $notifiable->routes['slack'] ?? null);
                $this->assertSame((string) $scan->getKey(), (string) $notification->scan->getKey());
                $this->assertSame(1, $notification->total);
                $this->assertSame(1, $notification->byImpact['critical']);

                return true;
            },
        );
    }

    #[Test]
    public function alerts_when_a_serious_violation_meets_the_threshold(): void
    {
        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            findings: [$this->findingWith('serious')],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(AccessibilityIssuesFound::class);
    }

    #[Test]
    public function stays_quiet_when_only_moderate_and_minor_violations_exist(): void
    {
        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            findings: [
                $this->findingWith('moderate'),
                $this->findingWith('minor', 'region'),
            ],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertNothingSent();
    }

    #[Test]
    public function stays_quiet_on_a_clean_scan(): void
    {
        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            findings: [],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertNothingSent();
    }

    #[Test]
    public function silently_no_ops_when_slack_channel_is_unconfigured(): void
    {
        Config::set('services.slack.notifications.channel', '');

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            findings: [$this->findingWith('critical')],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertNothingSent();
    }

    #[Test]
    public function alerts_on_failure_via_the_scan_failed_notification(): void
    {
        $failing = new class implements AccessibilityScannerClientContract
        {
            #[\Override]
            public function scan(Domain $domain, array $urls): AccessibilityScannerResult
            {
                throw new RuntimeException('Browser binding timeout');
            }
        };
        $this->app->instance(AccessibilityScannerClientContract::class, $failing);

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(
            AccessibilityScanFailed::class,
            function (AccessibilityScanFailed $notification): bool {
                $this->assertSame('Browser binding timeout', $notification->errorMessage);
                $this->assertSame(RuntimeException::class, $notification->errorClass);

                return true;
            },
        );
    }

    #[Test]
    public function threshold_can_be_lowered_to_minor_via_config(): void
    {
        Config::set('services.accessibility.alert_threshold', 'minor');

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            findings: [$this->findingWith('minor', 'region')],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(AccessibilityIssuesFound::class);
    }
}
