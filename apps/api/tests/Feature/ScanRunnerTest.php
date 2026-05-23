<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CookieCategory;
use App\Enums\CookieResolutionSource;
use App\Enums\CookieSource;
use App\Enums\FindingParty;
use App\Enums\FindingType;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Cookie;
use App\Models\Domain;
use App\Models\ScanFinding;
use App\Services\Scanner\ScannerClientContract;
use App\Services\Scanner\ScannerResult;
use App\Services\Scanner\ScanRunner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

class ScanRunnerTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create();

        Cookie::create([
            'name_pattern' => '_ga_*',
            'vendor' => 'Google Analytics',
            'category' => CookieCategory::Statistics,
            'purpose' => 'GA4 property-scoped session identifier',
            'source' => CookieSource::Manual,
        ]);
    }

    private function runner(): ScanRunner
    {
        return $this->app->make(ScanRunner::class);
    }

    #[Test]
    public function a_successful_scan_writes_findings_and_marks_completed(): void
    {
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 5,
            urlsScanned: 5,
            cookies: [
                ['name' => '_ga_ABC123', 'domain' => '.example.test', 'party' => 'third', 'first_seen_url' => 'https://example.test/'],
                ['name' => '_unknown_marketing', 'domain' => '.tracker.test', 'party' => 'third'],
            ],
            scripts: [
                ['name' => 'https://googletagmanager.com/gtm.js', 'party' => 'third'],
            ],
            iframes: [],
            requests: [],
        ));

        $scan = $this->runner()->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        $this->assertSame(ScanStatus::Completed, $scan->getAttribute('status'));
        $this->assertSame(5, $scan->getAttribute('urls_discovered'));
        $this->assertSame(5, $scan->getAttribute('urls_scanned'));
        $this->assertNotNull($scan->getAttribute('started_at'));
        $this->assertNotNull($scan->getAttribute('finished_at'));

        $findings = $scan->findings()->get();
        $this->assertCount(3, $findings);

        $ga = $findings->firstWhere('name', '_ga_ABC123');
        $this->assertInstanceOf(ScanFinding::class, $ga);
        $this->assertSame(FindingType::Cookie, $ga->getAttribute('type'));
        $this->assertSame(CookieCategory::Statistics, $ga->getAttribute('category'));
        $this->assertSame(CookieResolutionSource::Canonical, $ga->getAttribute('category_source'));
        $this->assertSame(FindingParty::Third, $ga->getAttribute('party'));

        $unknown = $findings->firstWhere('name', '_unknown_marketing');
        $this->assertInstanceOf(ScanFinding::class, $unknown);
        $this->assertNull($unknown->getAttribute('category'));
        $this->assertNull($unknown->getAttribute('category_source'));
    }

    #[Test]
    public function a_failed_scan_records_the_error_and_flips_status(): void
    {
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 0,
            urlsScanned: 0,
            cookies: [],
            scripts: [],
            iframes: [],
            requests: [],
        ));

        // Force a failure by binding a scanner that throws.
        $this->app->instance(
            ScannerClientContract::class,
            new class implements ScannerClientContract
            {
                #[\Override]
                public function scan($domain, array $urls): ScannerResult
                {
                    throw new \RuntimeException('Upstream CF Browser Rendering returned 502');
                }
            },
        );

        $scan = $this->runner()->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        $this->assertSame(ScanStatus::Failed, $scan->getAttribute('status'));
        $this->assertNotNull($scan->getAttribute('finished_at'));
        $this->assertSame(
            'Upstream CF Browser Rendering returned 502',
            $scan->getAttribute('error_log')['message'],
        );
        $this->assertCount(0, $scan->findings()->get());
    }

    #[Test]
    public function findings_inherit_organisation_id_from_the_parent_scan(): void
    {
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            cookies: [['name' => '_ga_ABC', 'domain' => '.example.test', 'party' => 'third']],
            scripts: [],
            iframes: [],
            requests: [],
        ));

        $scan = $this->runner()->run($this->domain, ['https://example.test/']);
        $finding = $scan->findings()->firstOrFail();

        $this->assertSame($this->org->getKey(), $finding->getAttribute('organisation_id'));
    }
}
