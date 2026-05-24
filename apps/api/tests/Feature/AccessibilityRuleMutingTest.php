<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanTrigger;
use App\Models\AccessibilityRuleOverride;
use App\Models\Domain;
use App\Notifications\AccessibilityIssuesFound;
use App\Services\Accessibility\AccessibilityScannerResult;
use App\Services\Accessibility\AccessibilityScanRunner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class AccessibilityRuleMutingTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.slack.notifications.channel', '#cmp-ops');
        Config::set('services.accessibility.alert_threshold', 'serious');
        Notification::fake();
        $this->domain = Domain::factory()->for($this->org)->create();
    }

    /**
     * @param  array<string, string>  $finding
     */
    private function finding(string $ruleId, string $impact = 'serious'): array
    {
        return [
            'rule_id' => $ruleId,
            'impact' => $impact,
            'description' => 'd', 'help_text' => 'h', 'help_url' => 'https://example.test/h',
            'url' => 'https://example.test/', 'tags' => [], 'nodes' => [],
        ];
    }

    #[Test]
    public function org_wide_mute_silences_an_alert_for_that_rule(): void
    {
        AccessibilityRuleOverride::create([
            'organisation_id' => $this->org->getKey(),
            'domain_id' => null,
            'rule_id' => 'color-contrast',
        ]);

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1, urlsScanned: 1,
            findings: [$this->finding('color-contrast')],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertNothingSent();
    }

    #[Test]
    public function domain_scoped_mute_silences_only_that_domain(): void
    {
        $otherDomain = Domain::factory()->for($this->org)->create();

        AccessibilityRuleOverride::create([
            'organisation_id' => $this->org->getKey(),
            'domain_id' => $this->domain->getKey(),
            'rule_id' => 'image-alt',
        ]);

        // Same finding on both domains — first should silence, second
        // should still alert.
        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1, urlsScanned: 1,
            findings: [$this->finding('image-alt', 'critical')],
        ));
        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);
        Notification::assertNothingSent();

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1, urlsScanned: 1,
            findings: [$this->finding('image-alt', 'critical')],
        ));
        $this->app->make(AccessibilityScanRunner::class)
            ->run($otherDomain, ['https://example.test/'], ScanTrigger::Manual);
        Notification::assertSentOnDemand(AccessibilityIssuesFound::class);
    }

    #[Test]
    public function mute_only_suppresses_matching_rule(): void
    {
        AccessibilityRuleOverride::create([
            'organisation_id' => $this->org->getKey(),
            'domain_id' => null,
            'rule_id' => 'color-contrast',
        ]);

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1, urlsScanned: 1,
            findings: [
                $this->finding('color-contrast'),      // muted
                $this->finding('image-alt', 'serious'), // not muted
            ],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(
            AccessibilityIssuesFound::class,
            function (AccessibilityIssuesFound $notification): bool {
                // Only image-alt should have counted toward the impact map.
                $this->assertSame(0, $notification->byImpact['critical']);
                $this->assertSame(1, $notification->byImpact['serious']);
                $this->assertSame(1, $notification->total);

                return true;
            },
        );
    }

    #[Test]
    public function show_endpoint_marks_muted_findings(): void
    {
        AccessibilityRuleOverride::create([
            'organisation_id' => $this->org->getKey(),
            'domain_id' => null,
            'rule_id' => 'color-contrast',
        ]);

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1, urlsScanned: 1,
            findings: [
                $this->finding('color-contrast'),
                $this->finding('image-alt'),
            ],
        ));

        $scan = $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/accessibility-scans/%s', $scan->getKey()))
            ->assertOk();

        $findings = collect($response->json('findings'))
            ->keyBy('ruleId');
        $this->assertTrue($findings['color-contrast']['muted']);
        $this->assertFalse($findings['image-alt']['muted']);
    }

    #[Test]
    public function create_endpoint_persists_an_override(): void
    {
        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/accessibility-rule-overrides', [
                'rule_id' => 'color-contrast',
                'reason' => 'Brand colour failed a recent contrast audit; remediation tracked in Linear ENG-123.',
            ])
            ->assertStatus(Response::HTTP_CREATED);

        $this->assertSame('color-contrast', $response->json('data.ruleId'));
        $this->assertDatabaseHas('accessibility_rule_overrides', [
            'organisation_id' => $this->org->getKey(),
            'rule_id' => 'color-contrast',
            'domain_id' => null,
        ]);
    }

    #[Test]
    public function list_returns_only_current_org_overrides(): void
    {
        AccessibilityRuleOverride::create([
            'organisation_id' => $this->org->getKey(),
            'domain_id' => null,
            'rule_id' => 'mine',
        ]);
        AccessibilityRuleOverride::create([
            'organisation_id' => $this->otherOrg->getKey(),
            'domain_id' => null,
            'rule_id' => 'theirs',
        ]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/accessibility-rule-overrides')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('mine', $response->json('data.0.ruleId'));
    }

    #[Test]
    public function delete_removes_the_override(): void
    {
        $override = AccessibilityRuleOverride::create([
            'organisation_id' => $this->org->getKey(),
            'domain_id' => null,
            'rule_id' => 'color-contrast',
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->deleteJson(sprintf('/api/accessibility-rule-overrides/%s', $override->getKey()))
            ->assertStatus(Response::HTTP_NO_CONTENT);

        $this->assertDatabaseMissing('accessibility_rule_overrides', ['id' => $override->getKey()]);
    }
}
