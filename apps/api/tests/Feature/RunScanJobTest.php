<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\RunScanJob;
use App\Models\Domain;
use App\Models\Scan;
use App\Services\Scanner\ScannerResult;
use App\Services\Scanner\ScanRunner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

class RunScanJobTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create();
    }

    #[Test]
    public function handle_runs_the_scan_and_flips_status_to_completed(): void
    {
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            cookies: [['name' => '_test_cookie', 'domain' => '.example.test', 'party' => 'third']],
            scripts: [],
            iframes: [],
            requests: [],
        ));

        $scan = Scan::create([
            'domain_id' => $this->domain->getKey(),
            'status' => ScanStatus::Queued,
            'trigger' => ScanTrigger::Manual,
        ]);

        $job = new RunScanJob($scan->getKey(), ['https://example.test/']);
        $job->handle($this->app->make(ScanRunner::class));

        $scan->refresh();
        $this->assertSame(ScanStatus::Completed, $scan->getAttribute('status'));
        $this->assertSame(1, $scan->findings()->count());
    }

    #[Test]
    public function handle_does_nothing_when_the_scan_id_is_gone(): void
    {
        $job = new RunScanJob('00000000-0000-0000-0000-000000000000', ['https://example.test/']);
        $job->handle($this->app->make(ScanRunner::class));

        $this->assertSame(0, Scan::query()->count());
    }
}
