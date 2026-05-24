<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanCadence;
use App\Enums\ScanStatus;
use App\Models\Domain;
use App\Services\Accessibility\AccessibilityScannerResult;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class AccessibilityScansTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create();
    }

    #[Test]
    public function triggering_an_a11y_scan_queues_a_run_and_returns_202(): void
    {
        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            findings: [],
        ));

        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/domains/%s/accessibility-scans', $this->domain->getKey()))
            ->assertStatus(Response::HTTP_ACCEPTED);

        $this->assertNotNull($response->json('data.id'));
        $this->assertSame($this->domain->getKey(), $response->json('data.domainId'));
    }

    #[Test]
    public function running_a_scan_persists_findings_and_counts_by_impact(): void
    {
        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            findings: [
                [
                    'rule_id' => 'color-contrast',
                    'impact' => 'serious',
                    'description' => 'Elements must have sufficient color contrast',
                    'help_text' => 'Help text',
                    'help_url' => 'https://dequeuniversity.com/rules/axe/4.x/color-contrast',
                    'url' => 'https://example.test/',
                    'tags' => ['wcag2aa', 'wcag143'],
                    'nodes' => [['target' => ['#a'], 'html' => '<p>x</p>', 'failureSummary' => 'fix this']],
                ],
                [
                    'rule_id' => 'image-alt',
                    'impact' => 'critical',
                    'description' => 'Images must have alternate text',
                    'help_text' => 'Help text',
                    'help_url' => 'https://dequeuniversity.com/rules/axe/4.x/image-alt',
                    'url' => 'https://example.test/about',
                    'tags' => ['wcag2a', 'wcag111'],
                    'nodes' => [['target' => ['img'], 'html' => '<img>', 'failureSummary' => 'add alt']],
                ],
            ],
        ));

        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/domains/%s/accessibility-scans', $this->domain->getKey()))
            ->assertStatus(Response::HTTP_ACCEPTED);

        $scanId = $response->json('data.id');

        // Job runs sync in tests (QUEUE_CONNECTION=sync per phpunit.xml).
        $this->assertDatabaseHas('accessibility_scans', [
            'id' => $scanId,
            'status' => ScanStatus::Completed->value,
            'violations_total' => 2,
        ]);
        $this->assertDatabaseCount('accessibility_findings', 2);

        $detail = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/accessibility-scans/%s', $scanId))
            ->assertOk();

        // Findings come back ordered Critical → Serious → ... so the
        // critical image-alt finding leads.
        $findings = $detail->json('findings');
        $this->assertCount(2, $findings);
        $this->assertSame('image-alt', $findings[0]['ruleId']);
        $this->assertSame('critical', $findings[0]['impact']);
        $this->assertSame('color-contrast', $findings[1]['ruleId']);

        $byImpact = $detail->json('scan.violationsByImpact');
        $this->assertSame(1, $byImpact['critical']);
        $this->assertSame(1, $byImpact['serious']);
        $this->assertSame(0, $byImpact['moderate']);
        $this->assertSame(0, $byImpact['minor']);
    }

    #[Test]
    public function listing_scans_filters_by_domain(): void
    {
        $other = Domain::factory()->for($this->org)->create();

        $this->a11yScanner->setNext(new AccessibilityScannerResult(0, 0, []));
        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/domains/%s/accessibility-scans', $this->domain->getKey()))
            ->assertStatus(Response::HTTP_ACCEPTED);

        $this->a11yScanner->setNext(new AccessibilityScannerResult(0, 0, []));
        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/domains/%s/accessibility-scans', $other->getKey()))
            ->assertStatus(Response::HTTP_ACCEPTED);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/accessibility-scans?domain_id=%s', $this->domain->getKey()))
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($this->domain->getKey(), $response->json('data.0.domainId'));
    }

    #[Test]
    public function tenant_cannot_view_another_orgs_scans(): void
    {
        // Seed the foreign-org scan directly rather than via the API so
        // we don't have to re-auth mid-test.
        $foreignDomain = Domain::factory()->for($this->otherOrg)->create();
        $foreignScan = \App\Models\AccessibilityScan::create([
            'domain_id' => $foreignDomain->getKey(),
            'status' => ScanStatus::Completed,
            'trigger' => \App\Enums\ScanTrigger::Manual,
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/accessibility-scans/%s', $foreignScan->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function update_domain_endpoint_persists_a11y_scan_cadence(): void
    {
        $response = $this->actingAsMember($this->user, $this->org)
            ->patchJson(sprintf('/api/domains/%s', $this->domain->getKey()), [
                'a11y_scan_cadence' => ScanCadence::Weekly->value,
            ])
            ->assertOk();

        $this->assertSame(ScanCadence::Weekly->value, $response->json('data.a11yScanCadence'));
        $this->assertSame(
            ScanCadence::Weekly,
            $this->domain->fresh()->getAttribute('a11y_scan_cadence'),
        );
    }

    #[Test]
    public function scan_failure_records_status_and_error_log(): void
    {
        // The default Array scanner returns an empty result. To exercise
        // the failure branch we swap in a stub that throws.
        $failing = new class implements \App\Services\Accessibility\AccessibilityScannerClientContract
        {
            #[\Override]
            public function scan(\App\Models\Domain $domain, array $urls): AccessibilityScannerResult
            {
                throw new \RuntimeException('Browser binding timeout');
            }
        };
        $this->app->instance(
            \App\Services\Accessibility\AccessibilityScannerClientContract::class,
            $failing,
        );

        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/domains/%s/accessibility-scans', $this->domain->getKey()))
            ->assertStatus(Response::HTTP_ACCEPTED);

        $this->assertDatabaseHas('accessibility_scans', [
            'id' => $response->json('data.id'),
            'status' => ScanStatus::Failed->value,
        ]);
    }
}
