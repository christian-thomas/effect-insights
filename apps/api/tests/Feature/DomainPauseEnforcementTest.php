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

/**
 * Covers the contract: the admin's domain status is the source of truth
 * for whether visitors see a banner. Flipping a domain Active → Paused
 * has to take the KV record offline; flipping back has to restore it.
 */
class DomainPauseEnforcementTest extends TenantTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validDraftPayload(): array
    {
        return [
            'layout' => BannerLayout::Modal,
            'locale_default' => 'en',
            'locales_enabled' => ['en'],
            'colors' => BannerConfigService::defaultColors(),
            'copy' => BannerConfigService::defaultCopy(),
            'gcm_defaults' => BannerConfigService::defaultGcmDefaults(),
            'geo_rules' => BannerConfigService::defaultGeoRules(),
            'behaviour' => BannerConfigService::defaultBehaviour(),
        ];
    }

    private function publishDraftFor(Domain $domain): void
    {
        $draftUrl = sprintf('/api/domains/%s/banner-config/draft', $domain->getKey());
        $publishUrl = sprintf('/api/domains/%s/banner-config/publish', $domain->getKey());

        $this->actingAsMember($this->user, $this->org)
            ->putJson($draftUrl, $this->validDraftPayload())
            ->assertOk();

        $this->actingAsMember($this->user, $this->org)
            ->postJson($publishUrl)
            ->assertOk();
    }

    #[Test]
    public function publishing_to_a_pending_domain_does_not_expose_the_config_to_the_edge(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_pending',
            'status' => DomainStatus::Pending,
        ]);

        $this->publishDraftFor($domain);

        // The DB row should be marked published — the editor / admin still
        // need a canonical version to work from. But the visitor-facing
        // KV record must stay absent until the domain goes Active.
        $this->assertDatabaseHas('banner_configs', [
            'domain_id' => $domain->getKey(),
            'is_published' => true,
            'version' => 1,
        ]);
        $this->assertNull($this->kv->get('pk_pending'));
    }

    #[Test]
    public function publishing_to_a_paused_domain_does_not_expose_the_config_to_the_edge(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_paused',
            'status' => DomainStatus::Paused,
        ]);

        $this->publishDraftFor($domain);

        $this->assertNull($this->kv->get('pk_paused'));
    }

    #[Test]
    public function flipping_an_active_domain_to_paused_clears_its_kv_record(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_about_to_pause',
            'status' => DomainStatus::Active,
        ]);

        $this->publishDraftFor($domain);
        $this->assertNotNull($this->kv->get('pk_about_to_pause'));

        $this->actingAsMember($this->user, $this->org)
            ->patchJson(sprintf('/api/domains/%s', $domain->getKey()), [
                'status' => DomainStatus::Paused,
            ])
            ->assertOk();

        $this->assertNull($this->kv->get('pk_about_to_pause'));
    }

    #[Test]
    public function flipping_a_paused_domain_back_to_active_restores_its_kv_record(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_resume',
            'status' => DomainStatus::Active,
        ]);

        $this->publishDraftFor($domain);

        $this->actingAsMember($this->user, $this->org)
            ->patchJson(sprintf('/api/domains/%s', $domain->getKey()), [
                'status' => DomainStatus::Paused,
            ])
            ->assertOk();
        $this->assertNull($this->kv->get('pk_resume'));

        $this->actingAsMember($this->user, $this->org)
            ->patchJson(sprintf('/api/domains/%s', $domain->getKey()), [
                'status' => DomainStatus::Active,
            ])
            ->assertOk();

        $payload = $this->kv->get('pk_resume');
        $this->assertNotNull($payload);
        $this->assertSame('pk_resume', $payload['domainPublicKey']);
        $this->assertSame(1, $payload['version']);
    }

    #[Test]
    public function activating_a_pending_domain_with_no_published_config_is_a_silent_noop(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_fresh',
            'status' => DomainStatus::Pending,
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->patchJson(sprintf('/api/domains/%s', $domain->getKey()), [
                'status' => DomainStatus::Active,
            ])
            ->assertOk();

        // No config has ever been published — KV stays empty, cmp.js will
        // 404 from the config worker and silently skip rendering.
        $this->assertNull($this->kv->get('pk_fresh'));
    }

    #[Test]
    public function deleting_a_domain_clears_the_kv_record(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_doomed',
            'status' => DomainStatus::Active,
        ]);

        $this->publishDraftFor($domain);
        $this->assertNotNull($this->kv->get('pk_doomed'));

        $this->actingAsMember($this->user, $this->org)
            ->deleteJson(sprintf('/api/domains/%s', $domain->getKey()))
            ->assertNoContent();

        $this->assertNull($this->kv->get('pk_doomed'));
    }

    #[Test]
    public function resign_command_skips_paused_domains(): void
    {
        $activeDomain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_active_resign',
            'status' => DomainStatus::Active,
        ]);
        $pausedDomain = Domain::factory()->for($this->org)->create([
            'public_key' => 'pk_paused_resign',
            'status' => DomainStatus::Paused,
        ]);

        foreach ([$activeDomain, $pausedDomain] as $domain) {
            BannerConfig::create([
                'domain_id' => $domain->getKey(),
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
                'published_at' => now(),
            ]);
        }

        $this->artisan('banner-configs:re-sign')
            ->expectsOutputToContain('Re-signed 1 banner config(s). 1 skipped (non-active).')
            ->assertSuccessful();

        $this->assertNotNull($this->kv->get('pk_active_resign'));
        $this->assertNull($this->kv->get('pk_paused_resign'));
    }
}
