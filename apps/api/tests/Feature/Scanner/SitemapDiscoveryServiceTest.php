<?php

declare(strict_types=1);

namespace Tests\Feature\Scanner;

use App\Models\Domain;
use App\Services\Scanner\SitemapDiscoveryService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

class SitemapDiscoveryServiceTest extends TenantTestCase
{
    private Domain $domain;

    private SitemapDiscoveryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create([
            'fqdn' => 'example.com',
            'apex_domain' => 'example.com',
        ]);
        $this->service = new SitemapDiscoveryService(resolve(HttpFactory::class));
    }

    #[Test]
    public function returns_apex_only_when_no_sitemap_and_no_robots(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('', 404),
            'https://example.com/robots.txt' => Http::response('', 404),
        ]);

        $urls = $this->service->discover($this->domain);

        $this->assertSame(['https://example.com/'], $urls);
    }

    #[Test]
    public function parses_a_simple_sitemap(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlsetXml([
                'https://example.com/about',
                'https://example.com/contact',
                'https://example.com/pricing/',
            ]), 200),
            'https://example.com/robots.txt' => Http::response('', 404),
        ]);

        $urls = $this->service->discover($this->domain);

        $this->assertSame([
            'https://example.com/',
            'https://example.com/about',
            'https://example.com/contact',
            'https://example.com/pricing/',
        ], $urls);
    }

    #[Test]
    public function follows_a_sitemap_index(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->sitemapIndexXml([
                'https://example.com/sitemap-products.xml',
                'https://example.com/sitemap-blog.xml',
            ]), 200),
            'https://example.com/sitemap-products.xml' => Http::response($this->urlsetXml([
                'https://example.com/products/a',
                'https://example.com/products/b',
            ]), 200),
            'https://example.com/sitemap-blog.xml' => Http::response($this->urlsetXml([
                'https://example.com/blog/post-1',
            ]), 200),
            'https://example.com/robots.txt' => Http::response('', 404),
        ]);

        $urls = $this->service->discover($this->domain);

        $this->assertContains('https://example.com/products/a', $urls);
        $this->assertContains('https://example.com/products/b', $urls);
        $this->assertContains('https://example.com/blog/post-1', $urls);
    }

    #[Test]
    public function falls_back_to_sitemaps_listed_in_robots_txt(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('', 404),
            'https://example.com/robots.txt' => Http::response(
                "User-agent: *\nDisallow:\nSitemap: https://example.com/custom-sitemap.xml\n",
                200,
            ),
            'https://example.com/custom-sitemap.xml' => Http::response($this->urlsetXml([
                'https://example.com/x',
            ]), 200),
        ]);

        $urls = $this->service->discover($this->domain);

        $this->assertContains('https://example.com/x', $urls);
    }

    #[Test]
    public function ignores_malformed_xml(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('<not valid xml<<', 200),
            'https://example.com/robots.txt' => Http::response('', 404),
        ]);

        $urls = $this->service->discover($this->domain);

        $this->assertSame(['https://example.com/'], $urls);
    }

    #[Test]
    public function filters_out_foreign_hosts(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlsetXml([
                'https://example.com/safe',
                'https://otherdomain.com/external',
                'https://www.example.com/subdomain-ok',
            ]), 200),
            'https://example.com/robots.txt' => Http::response('', 404),
        ]);

        $urls = $this->service->discover($this->domain);

        $this->assertContains('https://example.com/safe', $urls);
        $this->assertContains('https://www.example.com/subdomain-ok', $urls);
        $this->assertNotContains('https://otherdomain.com/external', $urls);
    }

    #[Test]
    public function caps_at_150_urls(): void
    {
        $tooMany = [];
        for ($i = 0; $i < 200; $i++) {
            $tooMany[] = sprintf('https://example.com/page-%d', $i);
        }

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlsetXml($tooMany), 200),
            'https://example.com/robots.txt' => Http::response('', 404),
        ]);

        $urls = $this->service->discover($this->domain);

        $this->assertCount(150, $urls);
        $this->assertSame('https://example.com/', $urls[0]);
    }

    #[Test]
    public function dedupes_apex_and_trailing_slashes(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($this->urlsetXml([
                'https://example.com/',
                'https://example.com/about',
                'https://example.com/about/',
            ]), 200),
            'https://example.com/robots.txt' => Http::response('', 404),
        ]);

        $urls = $this->service->discover($this->domain);

        $this->assertSame(['https://example.com/', 'https://example.com/about'], $urls);
    }

    /**
     * @param  array<int, string>  $locs
     */
    private function urlsetXml(array $locs): string
    {
        $entries = array_map(
            fn (string $loc): string => sprintf('  <url><loc>%s</loc></url>', htmlspecialchars($loc, ENT_XML1)),
            $locs,
        );

        return sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n%s\n</urlset>",
            implode("\n", $entries),
        );
    }

    /**
     * @param  array<int, string>  $locs
     */
    private function sitemapIndexXml(array $locs): string
    {
        $entries = array_map(
            fn (string $loc): string => sprintf('  <sitemap><loc>%s</loc></sitemap>', htmlspecialchars($loc, ENT_XML1)),
            $locs,
        );

        return sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n%s\n</sitemapindex>",
            implode("\n", $entries),
        );
    }
}
