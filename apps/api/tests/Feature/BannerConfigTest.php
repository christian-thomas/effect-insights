<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BannerConfig;
use App\Models\Domain;
use App\Services\BannerConfigService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class BannerConfigTest extends TenantTestCase
{
    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create();
    }

    private function validPayload(): array
    {
        return [
            'layout' => BannerConfig::LAYOUT_MODAL,
            'colors' => BannerConfigService::defaultColors(),
            'copy' => BannerConfigService::defaultCopy(),
        ];
    }

    #[Test]
    public function fetching_the_banner_config_creates_a_draft_when_missing(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/banner-config', $this->domain->getKey()))
            ->assertOk()
            ->assertJsonPath('draft.isDraft', true)
            ->assertJsonPath('draft.isPublished', false)
            ->assertJsonPath('published', null);

        $this->assertDatabaseCount('banner_configs', 1);
    }

    #[Test]
    public function updating_the_draft_mutates_the_same_row_idempotently(): void
    {
        $url = sprintf('/api/domains/%s/banner-config/draft', $this->domain->getKey());

        $this->actingAsMember($this->user, $this->org)
            ->putJson($url, $this->validPayload())
            ->assertOk();

        $this->actingAsMember($this->user, $this->org)
            ->putJson($url, array_merge($this->validPayload(), [
                'layout' => BannerConfig::LAYOUT_BAR_BOTTOM,
            ]))
            ->assertOk()
            ->assertJsonPath('data.layout', BannerConfig::LAYOUT_BAR_BOTTOM);

        $this->assertDatabaseCount('banner_configs', 1);
    }

    #[Test]
    public function draft_payload_is_validated(): void
    {
        $url = sprintf('/api/domains/%s/banner-config/draft', $this->domain->getKey());

        $this->actingAsMember($this->user, $this->org)
            ->putJson($url, [
                'layout' => 'sideways',
                'colors' => [],
                'copy' => [],
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['layout', 'colors.background', 'copy.title']);
    }

    #[Test]
    public function publishing_locks_the_draft_and_creates_the_next(): void
    {
        $draftUrl = sprintf('/api/domains/%s/banner-config/draft', $this->domain->getKey());
        $publishUrl = sprintf('/api/domains/%s/banner-config/publish', $this->domain->getKey());

        $this->actingAsMember($this->user, $this->org)
            ->putJson($draftUrl, $this->validPayload())
            ->assertOk();

        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson($publishUrl);

        $response->assertOk()
            ->assertJsonPath('data.isDraft', false)
            ->assertJsonPath('data.isPublished', true)
            ->assertJsonPath('data.version', 1);

        // The original draft row is now the published version, plus a fresh
        // draft has been created — 2 rows total.
        $this->assertDatabaseCount('banner_configs', 2);
        $this->assertEquals(1, BannerConfig::query()->where('is_published', true)->count());
        $this->assertEquals(1, BannerConfig::query()->where('is_draft', true)->count());
    }

    #[Test]
    public function publishing_again_demotes_the_previous_published_version(): void
    {
        $draftUrl = sprintf('/api/domains/%s/banner-config/draft', $this->domain->getKey());
        $publishUrl = sprintf('/api/domains/%s/banner-config/publish', $this->domain->getKey());

        $this->actingAsMember($this->user, $this->org)
            ->putJson($draftUrl, $this->validPayload())
            ->assertOk();
        $this->actingAsMember($this->user, $this->org)
            ->postJson($publishUrl)
            ->assertJsonPath('data.version', 1);

        $this->actingAsMember($this->user, $this->org)
            ->putJson($draftUrl, $this->validPayload())
            ->assertOk();
        $this->actingAsMember($this->user, $this->org)
            ->postJson($publishUrl)
            ->assertJsonPath('data.version', 2);

        $this->assertEquals(1, BannerConfig::query()->where('is_published', true)->count());
    }

    #[Test]
    public function banner_config_inherits_organisation_id_from_its_domain(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/banner-config', $this->domain->getKey()))
            ->assertOk();

        $config = BannerConfig::query()->where('domain_id', $this->domain->getKey())->first();
        $this->assertNotNull($config);
        $this->assertSame($this->org->getKey(), $config->getAttribute('organisation_id'));
    }

    #[Test]
    public function cross_tenant_banner_config_access_returns_404(): void
    {
        $foreignDomain = Domain::factory()->for($this->otherOrg)->create();

        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/banner-config', $foreignDomain->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }
}
