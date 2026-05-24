<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CookieCategory;
use App\Enums\CookieSource;
use App\Enums\ScanTrigger;
use App\Models\Cookie;
use App\Models\CookieOverride;
use App\Models\Domain;
use App\Services\Scanner\ScannerResult;
use App\Services\Scanner\ScanRunner;
use App\Support\CurrentOrganisation;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class ReviewQueueTest extends TenantTestCase
{
    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        // Suppress the unknown-cookie alert that fires on scan completion
        // so the test focuses on the review-queue behaviour.
        Notification::fake();

        $this->domain = Domain::factory()->for($this->org)->create(['fqdn' => 'example.test']);

        Cookie::create([
            'name_pattern' => '_known_*',
            'vendor' => 'Known Vendor',
            'category' => CookieCategory::Statistics,
            'purpose' => 'matched in canonical DB',
            'source' => CookieSource::Manual,
        ]);
    }

    private function runScan(string ...$cookieNames): void
    {
        $cookies = array_map(
            static fn (string $name): array => [
                'name' => $name,
                'domain' => '.tracker.test',
                'party' => 'third',
                'first_seen_url' => 'https://example.test/',
            ],
            $cookieNames,
        );
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            cookies: $cookies,
            scripts: [],
            iframes: [],
            requests: [],
        ));
        $this->app->make(ScanRunner::class)->run(
            $this->domain,
            ['https://example.test/'],
            ScanTrigger::Manual,
        );
    }

    #[Test]
    public function lists_uncategorised_cookies_grouped_by_name(): void
    {
        $this->runScan('_mystery_one', '_mystery_one', '_mystery_two', '_known_a');

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/scan-findings/review')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['_mystery_one', '_mystery_two'], $names);

        $mystery1 = collect($response->json('data'))->firstWhere('name', '_mystery_one');
        $this->assertSame(2, $mystery1['occurrences']);
        $this->assertSame('https://example.test/', $mystery1['firstSeenUrl']);
    }

    #[Test]
    public function excludes_names_that_an_existing_override_now_covers(): void
    {
        $this->runScan('_mystery_one', '_mystery_two');

        // Operator creates an override for _mystery_one — the queue should
        // drop it on the next GET even though the scan_findings row still
        // has category=NULL.
        CookieOverride::create([
            'organisation_id' => $this->org->getKey(),
            'name_pattern' => '_mystery_one',
            'category' => CookieCategory::Marketing,
        ]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/scan-findings/review')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['_mystery_two'], $names);
    }

    #[Test]
    public function does_not_leak_findings_from_another_org(): void
    {
        // Other org has its own scan with its own unknown cookies.
        $otherDomain = Domain::factory()->for($this->otherOrg)->create();
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            cookies: [
                ['name' => '_other_mystery', 'domain' => '.tracker.test', 'party' => 'third'],
            ],
            scripts: [],
            iframes: [],
            requests: [],
        ));
        // Switch context briefly so the scan runs under the other org.
        resolve(CurrentOrganisation::class)->set($this->otherOrg);
        $this->app->make(ScanRunner::class)->run(
            $otherDomain,
            ['https://other.test/'],
            ScanTrigger::Manual,
        );
        resolve(CurrentOrganisation::class)->set($this->org);

        $this->runScan('_mystery_one');

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/scan-findings/review')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['_mystery_one'], $names);
    }

    #[Test]
    public function unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/scan-findings/review')
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }
}
