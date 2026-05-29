<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanCadence;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\AccessibilityFinding;
use App\Models\AccessibilityScan;
use App\Models\Domain;
use App\Services\Accessibility\AccessibilityScannerClientContract;
use App\Services\Accessibility\AccessibilityScannerResult;
use Illuminate\Support\Facades\Storage;
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
        $foreignScan = AccessibilityScan::create([
            'domain_id' => $foreignDomain->getKey(),
            'status' => ScanStatus::Completed,
            'trigger' => ScanTrigger::Manual,
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
        $failing = new class implements AccessibilityScannerClientContract
        {
            #[\Override]
            public function scan(
                Domain $domain,
                array $urls,
                string $organisationId,
                string $scanId,
            ): AccessibilityScannerResult {
                throw new \RuntimeException('Browser binding timeout');
            }
        };
        $this->app->instance(
            AccessibilityScannerClientContract::class,
            $failing,
        );

        // Exercises the synchronous `run()` failure branch — the
        // dispatch() flow goes via Bus::batch and a real worker would
        // record the per-job failure in Horizon and let `finaliseBatch`
        // mark the scan failed; the sync queue driver used in tests
        // rethrows instead, so we test the failure-persistence logic
        // through the synchronous path that already owns it.
        $scan = $this->app->make(\App\Services\Accessibility\AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        $this->assertSame(ScanStatus::Failed, $scan->getAttribute('status'));
        $this->assertSame('Browser binding timeout', $scan->getAttribute('error_log')['message'] ?? null);
    }

    #[Test]
    public function persists_finding_screenshot_key_when_worker_returns_one(): void
    {
        // The worker now uploads PNGs to R2 directly and returns just
        // the key on the first node — no base64 in transit. The runner
        // just persists the key string and strips it off the node JSON.
        $expectedKey = sprintf('%s/%s/abc-def-uuid.png', $this->org->getKey(), 'will-be-set-below');

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
                    'tags' => ['wcag2aa'],
                    'nodes' => [
                        [
                            'target' => ['#a'],
                            'html' => '<p>x</p>',
                            'failureSummary' => 'fix this',
                            'screenshotKey' => 'fake-org/fake-scan/fake-uuid.png',
                        ],
                    ],
                ],
            ],
        ));

        $scan = $this->app->make(\App\Services\Accessibility\AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        /** @var AccessibilityFinding $finding */
        $finding = $scan->findings()->firstOrFail();

        // Key is passed through verbatim — Laravel doesn't reconstruct
        // the path now; that's the worker's job.
        $this->assertSame('fake-org/fake-scan/fake-uuid.png', $finding->getAttribute('screenshot_key'));

        // screenshotKey is stripped from the persisted nodes JSON so the
        // DB row doesn't double-store the key (it lives on the column).
        $nodes = $finding->getAttribute('nodes');
        $this->assertArrayNotHasKey('screenshotKey', $nodes[0]);
    }

    #[Test]
    public function show_finding_screenshot_streams_bytes_for_own_tenant(): void
    {
        Storage::fake('a11y_screenshots');

        $scan = AccessibilityScan::create([
            'domain_id' => $this->domain->getKey(),
            'status' => ScanStatus::Completed,
            'trigger' => ScanTrigger::Manual,
        ]);
        $finding = AccessibilityFinding::create([
            'accessibility_scan_id' => $scan->getKey(),
            'rule_id' => 'image-alt',
            'impact' => 'critical',
            'description' => 'Images must have alternate text',
            'help_text' => 'help',
            'help_url' => 'https://example.test/help',
            'url' => 'https://example.test/',
            'tags' => [],
            'nodes' => [],
        ]);

        $bytes = "\x89PNG\r\n\x1a\nmybytes";
        $key = sprintf('%s/%s/%s.png', $this->org->getKey(), $scan->getKey(), $finding->getKey());
        Storage::disk('a11y_screenshots')->put($key, $bytes);
        $finding->update(['screenshot_key' => $key]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->get(sprintf('/api/accessibility-findings/%s/screenshot', $finding->getKey()));

        $response->assertOk();
        $this->assertSame($bytes, $response->streamedContent());
    }

    #[Test]
    public function show_finding_screenshot_returns_404_for_other_tenant(): void
    {
        Storage::fake('a11y_screenshots');

        $foreignDomain = Domain::factory()->for($this->otherOrg)->create();
        $foreignScan = AccessibilityScan::create([
            'domain_id' => $foreignDomain->getKey(),
            'status' => ScanStatus::Completed,
            'trigger' => ScanTrigger::Manual,
        ]);
        $foreignFinding = AccessibilityFinding::create([
            'accessibility_scan_id' => $foreignScan->getKey(),
            'rule_id' => 'image-alt',
            'impact' => 'critical',
            'description' => 'desc',
            'help_text' => 'help',
            'help_url' => 'https://example.test/help',
            'url' => 'https://example.test/',
            'tags' => [],
            'nodes' => [],
            'screenshot_key' => 'foreign/key.png',
        ]);
        Storage::disk('a11y_screenshots')->put('foreign/key.png', 'bytes');

        $this->actingAsMember($this->user, $this->org)
            ->get(sprintf('/api/accessibility-findings/%s/screenshot', $foreignFinding->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function show_finding_screenshot_returns_404_when_key_missing(): void
    {
        Storage::fake('a11y_screenshots');

        $scan = AccessibilityScan::create([
            'domain_id' => $this->domain->getKey(),
            'status' => ScanStatus::Completed,
            'trigger' => ScanTrigger::Manual,
        ]);
        $finding = AccessibilityFinding::create([
            'accessibility_scan_id' => $scan->getKey(),
            'rule_id' => 'image-alt',
            'impact' => 'critical',
            'description' => 'desc',
            'help_text' => 'help',
            'help_url' => 'https://example.test/help',
            'url' => 'https://example.test/',
            'tags' => [],
            'nodes' => [],
            // screenshot_key intentionally null — element wasn't resolvable
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->get(sprintf('/api/accessibility-findings/%s/screenshot', $finding->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }
}
