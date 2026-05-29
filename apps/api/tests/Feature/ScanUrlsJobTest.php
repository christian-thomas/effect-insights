<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanUrlsJob;
use App\Models\Domain;
use App\Models\Scan;
use App\Services\Scanner\ScannerResult;
use App\Services\Scanner\ScanRunner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

class ScanUrlsJobTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create();
    }

    #[Test]
    public function handle_scans_a_chunk_and_increments_urls_scanned_by_chunk_size(): void
    {
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 3,
            urlsScanned: 3,
            cookies: [['name' => '_test_cookie', 'domain' => '.example.test', 'party' => 'third']],
            scripts: [],
            iframes: [],
            requests: [],
        ));

        $scan = Scan::create([
            'domain_id' => $this->domain->getKey(),
            'status' => ScanStatus::Running,
            'trigger' => ScanTrigger::Manual,
            'urls_discovered' => 3,
        ]);

        $job = new ScanUrlsJob($scan->getKey(), [
            'https://example.test/',
            'https://example.test/pricing',
            'https://example.test/about',
        ]);
        $job->handle($this->app->make(ScanRunner::class));

        $scan->refresh();
        // Status stays Running — finalisation is the batch's `finally`
        // hook's responsibility, not the per-chunk job's.
        $this->assertSame(ScanStatus::Running, $scan->getAttribute('status'));
        $this->assertSame(3, (int) $scan->getAttribute('urls_scanned'));
        $this->assertSame(1, $scan->findings()->count());
    }

    #[Test]
    public function handle_dedupes_findings_observed_on_multiple_chunks(): void
    {
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            cookies: [['name' => '_shared_cookie', 'domain' => '.example.test', 'party' => 'third']],
            scripts: [],
            iframes: [],
            requests: [],
        ));

        $scan = Scan::create([
            'domain_id' => $this->domain->getKey(),
            'status' => ScanStatus::Running,
            'trigger' => ScanTrigger::Manual,
            'urls_discovered' => 2,
        ]);

        $runner = $this->app->make(ScanRunner::class);

        // First chunk — inserts the cookie row.
        (new ScanUrlsJob($scan->getKey(), ['https://example.test/one']))->handle($runner);

        // Second chunk — same cookie. The unique
        // (scan_id, type, name_hash) index dedupes; row count stays at 1.
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            cookies: [['name' => '_shared_cookie', 'domain' => '.example.test', 'party' => 'third']],
            scripts: [],
            iframes: [],
            requests: [],
        ));
        (new ScanUrlsJob($scan->getKey(), ['https://example.test/two']))->handle($runner);

        $this->assertSame(1, $scan->findings()->count());
        $this->assertSame(2, (int) $scan->fresh()?->getAttribute('urls_scanned'));
    }

    #[Test]
    public function handle_does_nothing_when_the_scan_id_is_gone(): void
    {
        $job = new ScanUrlsJob('00000000-0000-0000-0000-000000000000', ['https://example.test/']);
        $job->handle($this->app->make(ScanRunner::class));

        $this->assertSame(0, Scan::query()->count());
    }
}
