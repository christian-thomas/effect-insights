<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Jobs\ScanUrlsJob;
use App\Models\Domain;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
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

    /**
     * @return list<string>
     */
    private function batchedUrls(PendingBatch $batch): array
    {
        return $batch->jobs
            ->flatMap(static fn (ScanUrlsJob $job): array => $job->urls)
            ->all();
    }

    #[Test]
    public function triggering_a_scan_returns_202_and_dispatches_a_batch_with_apex_only_when_no_sitemap(): void
    {
        Http::fake([
            '*/sitemap.xml' => Http::response('', 404),
            '*/robots.txt' => Http::response('', 404),
        ]);
        Bus::fake();

        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/domains/%s/scans', $this->domain->getKey()));

        $response
            ->assertStatus(Response::HTTP_ACCEPTED)
            ->assertJsonPath('data.status', ScanStatus::Running->value);

        $scanId = $response->json('data.id');

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->name === sprintf('scan:%s', $scanId)
            && $this->batchedUrls($batch) === ['https://example.test/']);
    }

    #[Test]
    public function triggering_a_scan_dispatches_one_job_per_discovered_url(): void
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
        Bus::fake();

        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/domains/%s/scans', $this->domain->getKey()))
            ->assertStatus(Response::HTTP_ACCEPTED);

        Bus::assertBatched(function (PendingBatch $batch): bool {
            $urls = $this->batchedUrls($batch);

            return in_array('https://example.test/', $urls, true)
                && in_array('https://example.test/pricing', $urls, true)
                && in_array('https://example.test/about', $urls, true);
        });
    }
}
