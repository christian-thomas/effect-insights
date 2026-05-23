<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Jobs\RunScanJob;
use App\Models\Domain;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class TriggerScanTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create([
            'fqdn' => 'example.test',
        ]);
    }

    #[Test]
    public function triggering_a_scan_returns_202_and_queues_a_run_job(): void
    {
        Queue::fake();

        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/domains/%s/scans', $this->domain->getKey()));

        $response
            ->assertStatus(Response::HTTP_ACCEPTED)
            ->assertJsonPath('data.status', ScanStatus::Queued->value);

        $scanId = $response->json('data.id');

        Queue::assertPushed(RunScanJob::class, static fn (RunScanJob $job): bool => $job->scanId === $scanId
            && $job->urls === ['https://example.test/']);
    }
}
