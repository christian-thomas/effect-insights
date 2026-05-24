<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BannerLayout;
use App\Enums\DomainStatus;
use App\Models\BannerConfig;
use App\Models\Domain;
use App\Services\BannerConfigService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

class ResignBannerConfigsCommandTest extends TenantTestCase
{
    private function makePublishedConfig(Domain $domain, int $version = 1): BannerConfig
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
            'published_at' => now(),
        ]);
    }

    #[Test]
    public function resigns_every_published_config_to_kv(): void
    {
        $domainA = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_a',
            'status' => DomainStatus::Active,
        ]);
        $domainB = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_b',
            'status' => DomainStatus::Active,
        ]);

        $this->makePublishedConfig($domainA);
        $this->makePublishedConfig($domainB);

        $this->artisan('banner-configs:re-sign')
            ->expectsOutputToContain('Re-signed 2 banner config(s). 0 skipped (non-active). 0 failed.')
            ->assertSuccessful();

        $this->assertNotNull($this->kv->get('pk_a'));
        $this->assertNotNull($this->kv->get('pk_b'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) $this->kv->get('pk_a')['signature'],
        );
    }

    #[Test]
    public function skips_unpublished_drafts(): void
    {
        $domain = Domain::factory()->for($this->org)->create(['public_key' => 'pk_draft_only']);

        // Draft row (not published) — should be ignored.
        BannerConfig::create([
            'domain_id' => $domain->getKey(),
            'version' => 0,
            'material_version' => 0,
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

        $this->artisan('banner-configs:re-sign')
            ->expectsOutputToContain('Re-signed 0 banner config(s)')
            ->assertSuccessful();

        $this->assertNull($this->kv->get('pk_draft_only'));
    }

    #[Test]
    public function picks_up_configs_across_every_tenant(): void
    {
        $domainOurOrg = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_ours',
            'status' => DomainStatus::Active,
        ]);
        $domainOtherOrg = Domain::factory()->for($this->otherOrg)->create([
            'public_key' => 'pk_theirs',
            'status' => DomainStatus::Active,
        ]);

        $this->makePublishedConfig($domainOurOrg);
        $this->makePublishedConfig($domainOtherOrg);

        $this->artisan('banner-configs:re-sign')
            ->expectsOutputToContain('Re-signed 2 banner config(s)')
            ->assertSuccessful();

        $this->assertNotNull($this->kv->get('pk_ours'));
        $this->assertNotNull($this->kv->get('pk_theirs'));
    }
}
