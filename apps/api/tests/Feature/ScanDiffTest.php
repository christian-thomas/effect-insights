<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CookieCategory;
use App\Enums\CookieSource;
use App\Enums\ScanTrigger;
use App\Models\Cookie;
use App\Models\Domain;
use App\Models\Scan;
use App\Services\Scanner\ScannerResult;
use App\Services\Scanner\ScanRunner;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

class ScanDiffTest extends TenantTestCase
{
    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->domain = Domain::factory()->for($this->org)->create();

        Cookie::create([
            'name_pattern' => '_ga_*',
            'vendor' => 'Google Analytics',
            'category' => CookieCategory::Statistics,
            'purpose' => 'GA4',
            'source' => CookieSource::Manual,
        ]);
    }

    private function runScan(string ...$cookieNames): Scan
    {
        $cookies = array_map(
            static fn (string $name): array => [
                'name' => $name,
                'domain' => '.example.test',
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

        return $this->app->make(ScanRunner::class)->run(
            $this->domain,
            ['https://example.test/'],
            ScanTrigger::Manual,
        );
    }

    #[Test]
    public function returns_null_diff_for_the_first_completed_scan_on_a_domain(): void
    {
        $scan = $this->runScan('_ga_ABC123');

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/scans/%s', $scan->getKey()))
            ->assertOk();

        $this->assertNull($response->json('diff'));
    }

    #[Test]
    public function surfaces_new_cookies_introduced_since_the_previous_scan(): void
    {
        $previous = $this->runScan('_ga_ABC123');
        // bump finished_at so ordering is unambiguous
        $previous->update(['finished_at' => now()->subMinute()]);

        $current = $this->runScan('_ga_ABC123', '_new_marketing_pixel');

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/scans/%s', $current->getKey()))
            ->assertOk();

        $this->assertSame($previous->getKey(), $response->json('diff.previousScanId'));
        $newNames = collect($response->json('diff.new'))->pluck('name')->all();
        $this->assertSame(['_new_marketing_pixel'], $newNames);
        $this->assertSame([], $response->json('diff.removed'));
        $this->assertSame([], $response->json('diff.categoryChanged'));
    }

    #[Test]
    public function surfaces_removed_cookies(): void
    {
        $previous = $this->runScan('_ga_ABC123', '_legacy_tracker');
        $previous->update(['finished_at' => now()->subMinute()]);

        $current = $this->runScan('_ga_ABC123');

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/scans/%s', $current->getKey()))
            ->assertOk();

        $removed = collect($response->json('diff.removed'))->pluck('name')->all();
        $this->assertSame(['_legacy_tracker'], $removed);
    }

    #[Test]
    public function surfaces_category_changes_between_scans(): void
    {
        $previous = $this->runScan('_unmapped_cookie'); // category=NULL initially
        $previous->update(['finished_at' => now()->subMinute()]);

        // Add a canonical match so the next scan resolves this name.
        Cookie::create([
            'name_pattern' => '_unmapped_cookie',
            'vendor' => 'Newly recognised',
            'category' => CookieCategory::Marketing,
            'purpose' => 'Now classified',
            'source' => CookieSource::Manual,
        ]);

        $current = $this->runScan('_unmapped_cookie');

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/scans/%s', $current->getKey()))
            ->assertOk();

        $changes = $response->json('diff.categoryChanged');
        $this->assertCount(1, $changes);
        $this->assertSame('_unmapped_cookie', $changes[0]['name']);
        $this->assertNull($changes[0]['previousCategory']);
        $this->assertSame('marketing', $changes[0]['currentCategory']);
    }

    #[Test]
    public function ignores_scans_on_other_domains_when_picking_the_baseline(): void
    {
        $otherDomain = Domain::factory()->for($this->org)->create(['fqdn' => 'other.test']);

        // Scan on the other domain — should NOT be used as the baseline.
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            cookies: [
                ['name' => '_irrelevant', 'domain' => '.other.test', 'party' => 'third'],
            ],
            scripts: [], iframes: [], requests: [],
        ));
        $this->app->make(ScanRunner::class)->run(
            $otherDomain,
            ['https://other.test/'],
            ScanTrigger::Manual,
        );

        $current = $this->runScan('_ga_ABC123');

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/scans/%s', $current->getKey()))
            ->assertOk();

        $this->assertNull($response->json('diff'));
    }
}
