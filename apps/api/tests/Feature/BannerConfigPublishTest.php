<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BannerConfig;
use App\Models\Domain;
use App\Services\BannerConfigService;
use App\Services\Cloudflare\CloudflareKvClientContract;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class BannerConfigPublishTest extends TenantTestCase
{
    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()
            ->for($this->org)
            ->create(['public_key' => 'pk_test_published_abc']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validDraftPayload(): array
    {
        return [
            'layout' => BannerConfig::LAYOUT_MODAL,
            'locale_default' => 'en',
            'locales_enabled' => ['en'],
            'colors' => BannerConfigService::defaultColors(),
            'copy' => BannerConfigService::defaultCopy(),
            'gcm_defaults' => BannerConfigService::defaultGcmDefaults(),
            'geo_rules' => BannerConfigService::defaultGeoRules(),
            'behaviour' => BannerConfigService::defaultBehaviour(),
        ];
    }

    private function saveDraftAndPublish(): void
    {
        $draftUrl = sprintf('/api/domains/%s/banner-config/draft', $this->domain->getKey());
        $publishUrl = sprintf('/api/domains/%s/banner-config/publish', $this->domain->getKey());

        $this->actingAsMember($this->user, $this->org)
            ->putJson($draftUrl, $this->validDraftPayload())
            ->assertOk();

        $this->actingAsMember($this->user, $this->org)
            ->postJson($publishUrl)
            ->assertOk();
    }

    #[Test]
    public function publishing_writes_the_banner_payload_to_cloudflare_kv(): void
    {
        $this->saveDraftAndPublish();

        $payload = $this->kv->get('pk_test_published_abc');

        $this->assertNotNull($payload, 'KV should contain the published config under the domain public_key');
        $this->assertSame('pk_test_published_abc', $payload['domainPublicKey']);
        $this->assertSame(1, $payload['version']);
        $this->assertSame('modal', $payload['layout']);
        $this->assertSame('unsigned', $payload['signature']);
    }

    #[Test]
    public function published_payload_carries_safe_default_gcm_signals(): void
    {
        $this->saveDraftAndPublish();

        $payload = $this->kv->get('pk_test_published_abc');
        $this->assertSame('denied', $payload['gcmDefaults']['analytics_storage']);
        $this->assertSame('denied', $payload['gcmDefaults']['ad_storage']);
        $this->assertSame('granted', $payload['gcmDefaults']['functionality_storage']);
        $this->assertSame('granted', $payload['gcmDefaults']['security_storage']);
    }

    #[Test]
    public function republishing_overwrites_the_kv_entry_with_the_new_version(): void
    {
        $draftUrl = sprintf('/api/domains/%s/banner-config/draft', $this->domain->getKey());
        $publishUrl = sprintf('/api/domains/%s/banner-config/publish', $this->domain->getKey());

        $this->actingAsMember($this->user, $this->org)
            ->putJson($draftUrl, $this->validDraftPayload())->assertOk();
        $this->actingAsMember($this->user, $this->org)
            ->postJson($publishUrl)->assertOk();

        $first = $this->kv->get('pk_test_published_abc');
        $this->assertSame(1, $first['version']);

        $this->actingAsMember($this->user, $this->org)
            ->putJson($draftUrl, $this->validDraftPayload())->assertOk();
        $this->actingAsMember($this->user, $this->org)
            ->postJson($publishUrl)->assertOk();

        $second = $this->kv->get('pk_test_published_abc');
        $this->assertSame(2, $second['version']);
    }

    #[Test]
    public function publish_failure_in_kv_rolls_back_the_database_changes(): void
    {
        $explodingKv = new class implements CloudflareKvClientContract
        {
            public function putValue(string $key, array $payload): void
            {
                throw new \RuntimeException('CF API unavailable');
            }

            public function deleteValue(string $key): void
            {
                throw new \RuntimeException('CF API unavailable');
            }
        };
        $this->app->instance(
            CloudflareKvClientContract::class,
            $explodingKv,
        );

        $draftUrl = sprintf('/api/domains/%s/banner-config/draft', $this->domain->getKey());
        $publishUrl = sprintf('/api/domains/%s/banner-config/publish', $this->domain->getKey());

        $this->actingAsMember($this->user, $this->org)
            ->putJson($draftUrl, $this->validDraftPayload())->assertOk();

        $this->actingAsMember($this->user, $this->org)
            ->postJson($publishUrl)
            ->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);

        // Draft should still be flagged as a draft — no half-published state.
        $this->assertDatabaseHas('banner_configs', [
            'domain_id' => $this->domain->getKey(),
            'is_draft' => true,
            'is_published' => false,
        ]);
        $this->assertDatabaseMissing('banner_configs', [
            'domain_id' => $this->domain->getKey(),
            'is_published' => true,
        ]);
    }
}
