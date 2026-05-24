<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanTrigger;
use App\Models\Domain;
use App\Notifications\AccessibilityIssuesFound;
use App\Services\Accessibility\AccessibilityScannerResult;
use App\Services\Accessibility\AccessibilityScanRunner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

class PerOrgA11yThresholdTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.slack.notifications.channel', '#cmp-ops');
        Config::set('services.accessibility.alert_threshold', 'serious'); // global
        Notification::fake();
        $this->domain = Domain::factory()->for($this->org)->create();
    }

    private function findingWith(string $impact): array
    {
        return [
            'rule_id' => 'region',
            'impact' => $impact,
            'description' => 'd', 'help_text' => 'h', 'help_url' => 'https://example.test/h',
            'url' => 'https://example.test/', 'tags' => [], 'nodes' => [],
        ];
    }

    #[Test]
    public function org_can_lower_threshold_to_minor_overriding_global_serious(): void
    {
        $this->org->update(['a11y_alert_threshold' => 'minor']);

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1, urlsScanned: 1,
            findings: [$this->findingWith('minor')],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(AccessibilityIssuesFound::class);
    }

    #[Test]
    public function org_can_raise_threshold_to_critical_overriding_global_serious(): void
    {
        $this->org->update(['a11y_alert_threshold' => 'critical']);

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1, urlsScanned: 1,
            findings: [$this->findingWith('serious')],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        // Serious < Critical for this org, so silent.
        Notification::assertNothingSent();
    }

    #[Test]
    public function null_per_org_threshold_falls_back_to_global(): void
    {
        $this->org->update(['a11y_alert_threshold' => null]);

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1, urlsScanned: 1,
            findings: [$this->findingWith('moderate')],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        // Global is 'serious'; moderate doesn't meet it.
        Notification::assertNothingSent();
    }

    #[Test]
    public function invalid_per_org_value_falls_back_to_global(): void
    {
        // Bypass FormRequest validation to seed a junk value — should be defensive.
        $this->org->update(['a11y_alert_threshold' => 'nope']);

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1, urlsScanned: 1,
            findings: [$this->findingWith('critical')],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(AccessibilityIssuesFound::class);
    }

    #[Test]
    public function update_organisation_endpoint_persists_threshold(): void
    {
        $superuser = $this->makeSuperuser($this->user);

        $response = $this->actingAsMember($superuser, $this->org)
            ->patchJson(sprintf('/api/organisations/%s', $this->org->getKey()), [
                'a11y_alert_threshold' => 'critical',
            ])
            ->assertOk();

        $this->assertSame('critical', $response->json('data.a11yAlertThreshold'));
    }

    #[Test]
    public function update_organisation_rejects_invalid_threshold(): void
    {
        $superuser = $this->makeSuperuser($this->user);

        $this->actingAsMember($superuser, $this->org)
            ->patchJson(sprintf('/api/organisations/%s', $this->org->getKey()), [
                'a11y_alert_threshold' => 'extreme',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['a11y_alert_threshold']);
    }
}
