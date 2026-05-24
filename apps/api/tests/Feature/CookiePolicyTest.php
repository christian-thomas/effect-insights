<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CookieCategory;
use App\Enums\DomainStatus;
use App\Enums\FindingParty;
use App\Enums\FindingType;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Domain;
use App\Models\Scan;
use App\Models\ScanFinding;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class CookiePolicyTest extends TenantTestCase
{
    private function makeCompletedScan(Domain $domain): Scan
    {
        return Scan::create([
            'domain_id' => $domain->getKey(),
            'status' => ScanStatus::Completed,
            'trigger' => ScanTrigger::Manual,
            'urls_discovered' => 1,
            'urls_scanned' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    private function addCookieFinding(
        Scan $scan,
        string $name,
        ?CookieCategory $category,
        ?string $purpose = null,
        ?string $domainSetOn = '.example.test',
    ): ScanFinding {
        return ScanFinding::create([
            'scan_id' => $scan->getKey(),
            'type' => FindingType::Cookie,
            'name' => $name,
            'domain_set_on' => $domainSetOn,
            'party' => FindingParty::Third,
            'category' => $category,
            'purpose_text' => $purpose,
            'first_seen_url' => 'https://example.test/',
        ]);
    }

    #[Test]
    public function json_endpoint_groups_cookies_by_category(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'status' => DomainStatus::Active,
            'public_key' => 'pk_policy_json',
        ]);
        $scan = $this->makeCompletedScan($domain);
        $this->addCookieFinding($scan, '_ga', CookieCategory::Statistics, 'Google Analytics');
        $this->addCookieFinding($scan, '_fbp', CookieCategory::Marketing, 'Meta Pixel');
        $this->addCookieFinding($scan, 'XSRF-TOKEN', CookieCategory::Necessary, 'Laravel CSRF token');

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/cookie-policy', $domain->getKey()))
            ->assertOk();

        $payload = $response->json('data');
        $this->assertSame('pk_policy_json', $payload['domain']['publicKey']);
        $categories = collect($payload['categories'])->keyBy('category');
        $this->assertCount(3, $categories);
        $this->assertSame('_ga', $categories['statistics']['cookies'][0]['name']);
        $this->assertSame('XSRF-TOKEN', $categories['necessary']['cookies'][0]['name']);
    }

    #[Test]
    public function json_endpoint_dedupes_cookies_by_name(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_policy_dedupe',
        ]);
        $scan = $this->makeCompletedScan($domain);
        // Same cookie name observed on three different pages.
        $this->addCookieFinding($scan, '_ga', CookieCategory::Statistics);
        $this->addCookieFinding($scan, '_ga', CookieCategory::Statistics);
        $this->addCookieFinding($scan, '_ga', CookieCategory::Statistics);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/cookie-policy', $domain->getKey()))
            ->assertOk();

        $cookies = $response->json('data.categories.0.cookies');
        $this->assertCount(1, $cookies);
        $this->assertSame('_ga', $cookies[0]['name']);
    }

    #[Test]
    public function uncategorised_cookies_land_in_the_unknown_bucket(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_policy_unknown',
        ]);
        $scan = $this->makeCompletedScan($domain);
        $this->addCookieFinding($scan, '_ga', CookieCategory::Statistics);
        $this->addCookieFinding($scan, 'mystery_token', null);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/cookie-policy', $domain->getKey()))
            ->assertOk();

        $unknown = collect($response->json('data.categories'))
            ->firstWhere('category', 'unknown');

        $this->assertNotNull($unknown);
        $this->assertSame('mystery_token', $unknown['cookies'][0]['name']);
    }

    #[Test]
    public function never_scanned_domains_return_an_empty_categories_array(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_policy_fresh',
        ]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/cookie-policy', $domain->getKey()))
            ->assertOk();

        $this->assertSame([], $response->json('data.categories'));
        $this->assertNull($response->json('data.scan'));
    }

    #[Test]
    public function script_and_iframe_findings_are_not_included(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_policy_cookies_only',
        ]);
        $scan = $this->makeCompletedScan($domain);
        $this->addCookieFinding($scan, '_ga', CookieCategory::Statistics);
        ScanFinding::create([
            'scan_id' => $scan->getKey(),
            'type' => FindingType::Script,
            'name' => 'gtm.js',
            'party' => FindingParty::Third,
            'category' => CookieCategory::Statistics,
        ]);
        ScanFinding::create([
            'scan_id' => $scan->getKey(),
            'type' => FindingType::Iframe,
            'name' => 'youtube',
            'party' => FindingParty::Third,
            'category' => CookieCategory::Marketing,
        ]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/cookie-policy', $domain->getKey()))
            ->assertOk();

        $allNames = collect($response->json('data.categories'))
            ->flatMap(fn (array $bucket): array => array_column($bucket['cookies'], 'name'))
            ->all();

        $this->assertSame(['_ga'], $allNames);
    }

    #[Test]
    public function tenant_cannot_read_another_orgs_cookie_policy(): void
    {
        $foreign = Domain::factory()->for($this->otherOrg)->create();
        $this->makeCompletedScan($foreign);

        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/cookie-policy', $foreign->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function public_html_endpoint_serves_the_policy_for_any_caller_by_public_key(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_policy_html_abc',
        ]);
        $scan = $this->makeCompletedScan($domain);
        $this->addCookieFinding($scan, '_ga', CookieCategory::Statistics, 'Google Analytics');

        $response = $this->get('/api/cookie-policy/pk_policy_html_abc.html');

        $response->assertOk();
        $this->assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        $body = $response->getContent();
        $this->assertStringContainsString('Statistics', (string) $body);
        $this->assertStringContainsString('_ga', (string) $body);
        $this->assertStringContainsString('Google Analytics', (string) $body);
    }

    #[Test]
    public function public_html_endpoint_returns_404_for_unknown_keys(): void
    {
        $this->get('/api/cookie-policy/pk_does_not_exist.html')
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function public_html_endpoint_sets_iframe_friendly_headers(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_policy_iframe',
        ]);
        $this->makeCompletedScan($domain);

        $response = $this->get('/api/cookie-policy/pk_policy_iframe.html');
        $response->assertOk();
        $this->assertSame('ALLOWALL', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
    }
}
