<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Jobs\RunScanJob;
use App\Models\Domain;
use Illuminate\Support\Facades\Http;
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
            'apex_domain' => 'example.test',
        ]);
    }

    #[Test]
    public function triggering_a_scan_returns_202_and_queues_a_run_job_with_apex_only_when_no_sitemap(): void
    {
        Http::fake([
            '*/sitemap.xml' => Http::response('', 404),
            '*/robots.txt' => Http::response('', 404),
        ]);
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

    #[Test]
    public function triggering_a_scan_includes_urls_discovered_from_sitemap_xml(): void
    {
        Http::fake([
            'https://example.test/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.test/pricing</loc></url>'
                .'<url><loc>https://example.test/about</loc></url>'
                .'</urlset>',
                200,
            ),
            '*/robots.txt' => Http::response('', 404),
        ]);
        Queue::fake();

        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/domains/%s/scans', $this->domain->getKey()))
            ->assertStatus(Response::HTTP_ACCEPTED);

        Queue::assertPushed(RunScanJob::class, static fn (RunScanJob $job): bool => in_array('https://example.test/', $job->urls, true)
            && in_array('https://example.test/pricing', $job->urls, true)
            && in_array('https://example.test/about', $job->urls, true));
    }
}
