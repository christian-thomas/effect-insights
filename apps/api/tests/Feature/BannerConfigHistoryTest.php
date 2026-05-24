<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BannerLayout;
use App\Enums\DomainStatus;
use App\Models\BannerConfig;
use App\Models\Domain;
use App\Services\BannerConfigService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class BannerConfigHistoryTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_history_abc',
            'status' => DomainStatus::Active,
        ]);
    }

    private function makeHistoricConfig(int $version, array $overrides = []): BannerConfig
    {
        return BannerConfig::create(array_merge([
            'domain_id' => $this->domain->getKey(),
            'version' => $version,
            'material_version' => $version,
            'is_draft' => false,
            'is_published' => false,
            'layout' => BannerLayout::Modal,
            'locale_default' => 'en',
            'locales_enabled' => ['en'],
            'colors' => BannerConfigService::defaultColors(),
            'copy' => BannerConfigService::defaultCopy(),
            'gcm_defaults' => BannerConfigService::defaultGcmDefaults(),
            'geo_rules' => BannerConfigService::defaultGeoRules(),
            'behaviour' => BannerConfigService::defaultBehaviour(),
            'published_at' => CarbonImmutable::now()->subDays($version),
        ], $overrides));
    }

    #[Test]
    public function history_endpoint_returns_published_rows_newest_first(): void
    {
        $this->makeHistoricConfig(1);
        $this->makeHistoricConfig(2, ['is_published' => true]);
        $this->makeHistoricConfig(3);
        // A draft must NOT appear in history.
        BannerConfig::create([
            'domain_id' => $this->domain->getKey(),
            'version' => 0,
            'material_version' => 3,
            'is_draft' => true,
            'is_published' => false,
            'layout' => BannerLayout::Modal,
            'locale_default' => 'en',
            'locales_enabled' => ['en'],
            'colors' => BannerConfigService::defaultColors(),
            'copy' => BannerConfigService::defaultCopy(),
            'gcm_defaults' => BannerConfigService::defaultGcmDefaults(),
            'geo_rules' => BannerConfigService::defaultGeoRules(),
            'behaviour' => BannerConfigService::defaultBehaviour(),
        ]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/banner-config/history', $this->domain->getKey()))
            ->assertOk();

        $versions = collect($response->json('data'))->pluck('version')->all();
        $this->assertSame([3, 2, 1], $versions);
    }

    #[Test]
    public function tenant_cannot_read_another_orgs_history(): void
    {
        $foreign = Domain::factory()->for($this->otherOrg)->create();

        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/banner-config/history', $foreign->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function rollback_clones_a_historic_version_into_the_draft(): void
    {
        // v1 had a different layout + custom colour we want back.
        $oldColors = BannerConfigService::defaultColors();
        $oldColors['primary'] = '#000000';
        $v1 = $this->makeHistoricConfig(1, [
            'layout' => BannerLayout::BarTop,
            'colors' => $oldColors,
        ]);
        // v2 is the current publish — also creates the draft via service.
        $this->actingAsMember($this->user, $this->org)
            ->putJson(sprintf('/api/domains/%s/banner-config/draft', $this->domain->getKey()), [
                'layout' => BannerLayout::Modal,
                'locale_default' => 'en',
                'locales_enabled' => ['en'],
                'colors' => BannerConfigService::defaultColors(),
                'copy' => BannerConfigService::defaultCopy(),
                'gcm_defaults' => BannerConfigService::defaultGcmDefaults(),
                'geo_rules' => BannerConfigService::defaultGeoRules(),
                'behaviour' => BannerConfigService::defaultBehaviour(),
            ])
            ->assertOk();

        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/domains/%s/banner-config/rollback', $this->domain->getKey()), [
                'version' => $v1->getAttribute('version'),
            ])
            ->assertOk();

        $this->assertSame('bar-top', $response->json('data.layout'));
        $this->assertSame('#000000', $response->json('data.colors.primary'));
        // The draft row is still a draft — rollback does NOT publish.
        $this->assertTrue($response->json('data.isDraft'));
        $this->assertFalse($response->json('data.isPublished'));
    }

    #[Test]
    public function rollback_404s_on_unknown_version(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/domains/%s/banner-config/rollback', $this->domain->getKey()), [
                'version' => 99,
            ])
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function rollback_rejects_non_positive_version(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/domains/%s/banner-config/rollback', $this->domain->getKey()), [
                'version' => 0,
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
