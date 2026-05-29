<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanAccessibilityUrlsJob;
use App\Jobs\ScanUrlsJob;
use App\Models\AccessibilityScan;
use App\Models\Domain;
use App\Models\Scan;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class RetryFailedScanTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create();
    }

    #[Test]
    public function cookie_retry_endpoint_dispatches_a_batch_over_just_the_failed_urls(): void
    {
        Bus::fake();

        $scan = Scan::create([
            'domain_id' => $this->domain->getKey(),
            'status' => ScanStatus::Partial,
            'trigger' => ScanTrigger::Manual,
            'urls_discovered' => 5,
            'urls_scanned' => 3,
            'failed_urls' => ['https://example.test/two', 'https://example.test/five'],
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/scans/%s/retry-failed', $scan->getKey()))
            ->assertStatus(Response::HTTP_ACCEPTED)
            ->assertJsonPath('data.status', ScanStatus::Running->value);

        // Scan resets in place: failed_urls cleared, error_log cleared,
        // urls_scanned untouched so progress resumes mid-fill.
        $scan->refresh();
        $this->assertSame(ScanStatus::Running, $scan->getAttribute('status'));
        $this->assertNull($scan->getAttribute('failed_urls'));
        $this->assertSame(3, (int) $scan->getAttribute('urls_scanned'));

        Bus::assertBatched(function (PendingBatch $batch) use ($scan): bool {
            $urls = $batch->jobs->flatMap(static fn (ScanUrlsJob $job): array => $job->urls)->all();

            return $batch->name === sprintf('scan-retry:%s', $scan->getKey())
                && in_array('https://example.test/two', $urls, true)
                && in_array('https://example.test/five', $urls, true)
                && count($urls) === 2;
        });
    }

    #[Test]
    public function cookie_retry_endpoint_returns_422_when_no_failed_urls(): void
    {
        Bus::fake();

        $scan = Scan::create([
            'domain_id' => $this->domain->getKey(),
            'status' => ScanStatus::Completed,
            'trigger' => ScanTrigger::Manual,
            'urls_discovered' => 3,
            'urls_scanned' => 3,
            'failed_urls' => null,
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/scans/%s/retry-failed', $scan->getKey()))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        Bus::assertNothingBatched();
    }

    #[Test]
    public function cookie_retry_endpoint_returns_404_for_other_tenant(): void
    {
        Bus::fake();

        $foreignDomain = Domain::factory()->for($this->otherOrg)->create();
        $foreignScan = Scan::create([
            'domain_id' => $foreignDomain->getKey(),
            'status' => ScanStatus::Partial,
            'trigger' => ScanTrigger::Manual,
            'urls_discovered' => 3,
            'urls_scanned' => 1,
            'failed_urls' => ['https://other.test/a'],
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/scans/%s/retry-failed', $foreignScan->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);

        Bus::assertNothingBatched();
    }

    #[Test]
    public function a11y_retry_endpoint_dispatches_a_batch_over_just_the_failed_urls(): void
    {
        Bus::fake();

        $scan = AccessibilityScan::create([
            'domain_id' => $this->domain->getKey(),
            'status' => ScanStatus::Partial,
            'trigger' => ScanTrigger::Manual,
            'urls_discovered' => 4,
            'urls_scanned' => 2,
            'failed_urls' => ['https://example.test/blog', 'https://example.test/login'],
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/accessibility-scans/%s/retry-failed', $scan->getKey()))
            ->assertStatus(Response::HTTP_ACCEPTED)
            ->assertJsonPath('data.status', ScanStatus::Running->value);

        Bus::assertBatched(function (PendingBatch $batch) use ($scan): bool {
            $urls = $batch->jobs->flatMap(static fn (ScanAccessibilityUrlsJob $job): array => $job->urls)->all();

            return $batch->name === sprintf('a11y-scan-retry:%s', $scan->getKey())
                && in_array('https://example.test/blog', $urls, true)
                && in_array('https://example.test/login', $urls, true);
        });
    }

    #[Test]
    public function a11y_retry_endpoint_returns_404_for_other_tenant(): void
    {
        Bus::fake();

        $foreignDomain = Domain::factory()->for($this->otherOrg)->create();
        $foreignScan = AccessibilityScan::create([
            'domain_id' => $foreignDomain->getKey(),
            'status' => ScanStatus::Partial,
            'trigger' => ScanTrigger::Manual,
            'urls_discovered' => 2,
            'urls_scanned' => 1,
            'failed_urls' => ['https://other.test/a'],
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/accessibility-scans/%s/retry-failed', $foreignScan->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);

        Bus::assertNothingBatched();
    }
}
