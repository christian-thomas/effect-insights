<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BannerLayout;
use App\Models\BannerConfig;
use App\Models\Domain;
use App\Services\BannerConfigService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class BannerConfigDiffTest extends TenantTestCase
{
    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create();
    }

    #[Test]
    public function returns_first_publish_when_no_published_row_exists(): void
    {
        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/banner-config/diff', $this->domain->getKey()))
            ->assertOk();

        $this->assertTrue($response->json('data.isFirstPublish'));
        $this->assertTrue($response->json('data.hasChanges'));
        $this->assertTrue($response->json('data.isMaterial'));
    }

    #[Test]
    public function returns_no_changes_when_draft_matches_published(): void
    {
        $this->publishBaseline();

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/banner-config/diff', $this->domain->getKey()))
            ->assertOk();

        $this->assertFalse($response->json('data.hasChanges'));
        $this->assertFalse($response->json('data.isMaterial'));
        $this->assertFalse($response->json('data.isFirstPublish'));
    }

    #[Test]
    public function reflects_a_material_change_to_the_draft(): void
    {
        $this->publishBaseline();

        // First call creates the draft (initially identical to published).
        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/banner-config/diff', $this->domain->getKey()))
            ->assertOk();

        // Now mutate the draft to flip GCM ad_storage to granted.
        $draft = BannerConfig::query()
            ->where('domain_id', $this->domain->getKey())
            ->where('is_draft', true)
            ->firstOrFail();
        $draft->update([
            'gcm_defaults' => [
                ...$draft->getAttribute('gcm_defaults'),
                'ad_storage' => 'granted',
            ],
        ]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/banner-config/diff', $this->domain->getKey()))
            ->assertOk();

        $this->assertTrue($response->json('data.hasChanges'));
        $this->assertTrue($response->json('data.isMaterial'));
        $this->assertContains(
            'gcm_defaults.ad_storage',
            collect($response->json('data.changes'))->pluck('field')->all(),
        );
    }

    #[Test]
    public function unauthenticated_request_is_rejected(): void
    {
        $this->getJson(sprintf('/api/domains/%s/banner-config/diff', $this->domain->getKey()))
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    private function publishBaseline(): void
    {
        BannerConfig::create([
            'domain_id' => $this->domain->getKey(),
            'version' => 1,
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
        ]);
        // The draft starts identical to the published row; the ShowBannerConfig
        // endpoint creates it on demand, but the diff endpoint also creates
        // it via getOrCreateDraft so the first GET works.
    }
}
