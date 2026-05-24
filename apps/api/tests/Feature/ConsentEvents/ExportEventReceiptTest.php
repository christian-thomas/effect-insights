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

class ExportEventReceiptTest extends TenantTestCase
{
    private Domain $domain;

    private Domain $otherDomain;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.consent_receipt.signing_key', 'test-receipt-key');

        $this->domain = Domain::factory()->for($this->org)->create(['fqdn' => 'example.com']);
        $this->otherDomain = Domain::factory()->for($this->otherOrg)->create(['fqdn' => 'other.com']);

        $this->seedPublishedBanner($this->domain, version: 3);
    }

    #[Test]
    public function returns_a_signed_json_receipt_for_an_event_in_the_current_org(): void
    {
        $event = ConsentEvent::factory()
            ->for($this->domain)
            ->create([
                'banner_config_version' => 3,
                'anonymous_id' => 'anon_alpha',
                'event_type' => ConsentEventType::Granted,
                'occurred_at' => CarbonImmutable::parse('2026-05-10T12:00:00Z'),
            ]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/consent-events/%s/receipt', $event->getKey()))
            ->assertOk();

        $payload = $response->json();
        $this->assertSame('event', $payload['mode']);
        $this->assertSame('1', $payload['formatVersion']);
        $this->assertSame('HMAC-SHA-256', $payload['signatureAlgorithm']);
        $this->assertSame('anon_alpha', $payload['event']['anonymousId']);
        $this->assertSame('granted', $payload['event']['eventType']);
        $this->assertSame(3, $payload['event']['bannerConfigVersion']);
        $this->assertSame('historical', $payload['bannerConfig']['snapshotNote']);
        $this->assertSame('example.com', $payload['domain']['fqdn']);

        $signature = $payload['signature'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);

        $signer = new ConsentReceiptSigner('test-receipt-key');
        $this->assertTrue($signer->verify($payload, $signature));

        $tampered = $payload;
        $tampered['event']['eventType'] = 'denied';
        $this->assertFalse($signer->verify($tampered, $signature));
    }

    #[Test]
    public function returns_a_pdf_when_format_is_pdf(): void
    {
        $event = ConsentEvent::factory()->for($this->domain)->create(['banner_config_version' => 3]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->get(sprintf('/api/consent-events/%s/receipt?format=pdf', $event->getKey()));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    #[Test]
    public function falls_back_to_current_published_banner_when_version_is_missing(): void
    {
        $event = ConsentEvent::factory()
            ->for($this->domain)
            ->create(['banner_config_version' => 99]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/consent-events/%s/receipt', $event->getKey()))
            ->assertOk();

        $this->assertSame('current_published', $response->json('bannerConfig.snapshotNote'));
        $this->assertSame(3, $response->json('bannerConfig.version'));
    }

    #[Test]
    public function cross_tenant_event_returns_404(): void
    {
        $otherEvent = ConsentEvent::factory()->for($this->otherDomain)->create();

        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/consent-events/%s/receipt', $otherEvent->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function unauthenticated_request_is_rejected(): void
    {
        $event = ConsentEvent::factory()->for($this->domain)->create();

        $this->getJson(sprintf('/api/consent-events/%s/receipt', $event->getKey()))
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function invalid_format_returns_422(): void
    {
        $event = ConsentEvent::factory()->for($this->domain)->create();

        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/consent-events/%s/receipt?format=xml', $event->getKey()))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
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
