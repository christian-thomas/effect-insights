<?php

declare(strict_types=1);

namespace Tests\Feature\ConsentEvents;

use App\Enums\BannerLayout;
use App\Enums\ConsentEventType;
use App\Models\BannerConfig;
use App\Models\ConsentEvent;
use App\Models\Domain;
use App\Services\BannerConfigService;
use App\Services\ConsentEvents\ConsentReceiptSigner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class ExportVisitorReceiptTest extends TenantTestCase
{
    private Domain $domain;

    private Domain $otherDomain;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.consent_receipt.signing_key', 'test-receipt-key');

        $this->domain = Domain::factory()->for($this->org)->create();
        $this->otherDomain = Domain::factory()->for($this->otherOrg)->create();

        $this->seedPublishedBanner($this->domain, version: 2);
    }

    #[Test]
    public function returns_a_bundle_receipt_for_a_visitor_with_multiple_events(): void
    {
        $earliest = ConsentEvent::factory()
            ->for($this->domain)
            ->create([
                'anonymous_id' => 'anon_target',
                'event_type' => ConsentEventType::Granted,
                'occurred_at' => CarbonImmutable::parse('2026-01-10T09:00:00Z'),
                'banner_config_version' => 2,
            ]);

        $latest = ConsentEvent::factory()
            ->for($this->domain)
            ->create([
                'anonymous_id' => 'anon_target',
                'event_type' => ConsentEventType::Withdrawn,
                'occurred_at' => CarbonImmutable::parse('2026-03-12T11:30:00Z'),
                'banner_config_version' => 2,
            ]);

        // Noise: different visitor + cross-tenant.
        ConsentEvent::factory()->for($this->domain)->create(['anonymous_id' => 'anon_someone_else']);
        ConsentEvent::factory()->for($this->otherDomain)->create(['anonymous_id' => 'anon_target']);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf(
                '/api/consent-events/receipts/by-anonymous-id?anonymous_id=anon_target&domain_id=%s',
                $this->domain->getKey(),
            ))
            ->assertOk();

        $payload = $response->json();
        $this->assertSame('visitor', $payload['mode']);
        $this->assertSame(2, $payload['eventCount']);
        $this->assertCount(2, $payload['events']);
        $this->assertSame($earliest->getKey(), $payload['events'][0]['id']);
        $this->assertSame($latest->getKey(), $payload['events'][1]['id']);
        $this->assertArrayHasKey('2', $payload['bannerConfigs']);

        $signer = new ConsentReceiptSigner('test-receipt-key');
        $this->assertTrue($signer->verify($payload, $payload['signature']));
    }

    #[Test]
    public function returns_pdf_when_format_is_pdf(): void
    {
        ConsentEvent::factory()->for($this->domain)->create([
            'anonymous_id' => 'anon_pdf',
            'banner_config_version' => 2,
        ]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->get(sprintf(
                '/api/consent-events/receipts/by-anonymous-id?anonymous_id=anon_pdf&domain_id=%s&format=pdf',
                $this->domain->getKey(),
            ));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    #[Test]
    public function returns_404_when_no_events_match(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf(
                '/api/consent-events/receipts/by-anonymous-id?anonymous_id=anon_nobody&domain_id=%s',
                $this->domain->getKey(),
            ))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function cross_tenant_domain_id_returns_404_via_org_scope(): void
    {
        // Rule::exists() in the FormRequest doesn't honour the BelongsToOrganisation
        // global scope (it's a raw table query), so validation accepts a cross-org
        // UUID. The controller's Domain::query()->firstOrFail() does honour the
        // scope and 404s — no information leak about whether the row exists.
        ConsentEvent::factory()->for($this->otherDomain)->create(['anonymous_id' => 'anon_target']);

        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf(
                '/api/consent-events/receipts/by-anonymous-id?anonymous_id=anon_target&domain_id=%s',
                $this->otherDomain->getKey(),
            ))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function missing_query_params_return_422(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/consent-events/receipts/by-anonymous-id')
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Test]
    public function unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/consent-events/receipts/by-anonymous-id')
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    private function seedPublishedBanner(Domain $domain, int $version): BannerConfig
    {
        return BannerConfig::create([
            'domain_id' => $domain->getKey(),
            'version' => $version,
            'material_version' => 1,
            'is_draft' => false,
            'is_published' => true,
            'layout' => BannerLayout::Modal,
            'locale_default' => 'en',
            'locales_enabled' => ['en'],
            'colors' => BannerConfigService::defaultColors(),
            'copy' => BannerConfigService::defaultCopy(),
            'gcm_defaults' => BannerConfigService::defaultGcmDefaults(),
            'geo_rules' => BannerConfigService::defaultGeoRules(),
            'behaviour' => BannerConfigService::defaultBehaviour(),
            'published_at' => CarbonImmutable::parse('2026-04-01T10:00:00Z'),
        ]);
    }
}
