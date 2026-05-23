<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Domain;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class BannerPreviewTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_test_preview',
        ]);
        config(['services.banner.cdn_url' => 'https://assets.test']);
    }

    #[Test]
    public function returns_html_with_inline_config_and_preview_mode(): void
    {
        $response = $this->actingAsMember($this->user, $this->org)
            ->get(sprintf('/api/domains/%s/banner-preview', $this->domain->getKey()));

        $response
            ->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')->assertSeeHtml('data-cmp-preview')->assertSeeHtml('data-cmp-config=')->assertSeeHtml('https://assets.test/cmp.js');

        // Symfony normalises the Cache-Control header by sorting + adding
        // `private`, so just assert the no-cache directives are present.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
    }

    #[Test]
    public function the_inline_config_contains_the_current_draft(): void
    {
        $response = $this->actingAsMember($this->user, $this->org)
            ->get(sprintf('/api/domains/%s/banner-preview', $this->domain->getKey()));

        $response->assertOk();

        // The draft is auto-created by BannerConfigService::getOrCreateDraft —
        // it carries the default copy ("We use cookies").
        $this->assertStringContainsString('We use cookies', $response->getContent() ?: '');
        // Preview payloads stamp the signature field so cmp.js (which does
        // its own preview-mode skip) can identify them.
        $this->assertStringContainsString('&quot;signature&quot;:&quot;preview&quot;', $response->getContent() ?: '');
    }

    #[Test]
    public function refuses_preview_for_a_domain_belonging_to_another_organisation(): void
    {
        $foreignDomain = Domain::factory()->for($this->otherOrg)->create();

        $this->actingAsMember($this->user, $this->org)
            ->get(sprintf('/api/domains/%s/banner-preview', $foreignDomain->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function requires_authentication(): void
    {
        // `Accept: application/json` ensures Sanctum returns 401 instead of
        // trying to redirect to a non-existent web login route.
        $this->getJson(sprintf('/api/domains/%s/banner-preview', $this->domain->getKey()))
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }
}
