<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BannerLayout;
use App\Models\BannerConfig;
use App\Models\Domain;
use App\Models\Organisation;
use App\Services\BannerConfigService;
use App\Support\BannerConfigDiffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BannerConfigDifferTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $organisation = Organisation::create(['name' => 'Diff Co', 'slug' => 'diff-co']);
        $this->domain = Domain::factory()->for($organisation)->create();
    }

    private function baseConfig(): BannerConfig
    {
        return BannerConfig::create([
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
    }

    private function draftFrom(BannerConfig $previous, callable $mutate): BannerConfig
    {
        $attributes = $previous->getAttributes();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
        $draft = BannerConfig::make($attributes);
        $draft->setAttribute('is_draft', true);
        $draft->setAttribute('is_published', false);
        $draft->setAttribute('layout', $previous->getAttribute('layout'));
        // Cast columns lose their decoded form when copying via getAttributes,
        // so reseed them from the source.
        foreach (['colors', 'copy', 'gcm_defaults', 'geo_rules', 'behaviour', 'locales_enabled'] as $field) {
            $draft->setAttribute($field, $previous->getAttribute($field));
        }
        $mutate($draft);

        return $draft;
    }

    #[Test]
    public function reports_first_publish_when_no_previous_exists(): void
    {
        $draft = $this->baseConfig();

        $diff = BannerConfigDiffer::diff(null, $draft);

        $this->assertTrue($diff['isFirstPublish']);
        $this->assertTrue($diff['hasChanges']);
        $this->assertTrue($diff['isMaterial']);
        $this->assertSame([], $diff['changes']);
    }

    #[Test]
    public function reports_no_changes_when_draft_matches_published(): void
    {
        $published = $this->baseConfig();
        $draft = $this->draftFrom($published, static fn (): null => null);

        $diff = BannerConfigDiffer::diff($published, $draft);

        $this->assertFalse($diff['hasChanges']);
        $this->assertFalse($diff['isMaterial']);
        $this->assertSame([], $diff['changes']);
    }

    #[Test]
    public function flags_gcm_default_changes_as_material(): void
    {
        $published = $this->baseConfig();
        $draft = $this->draftFrom($published, static function (BannerConfig $config): void {
            $config->setAttribute('gcm_defaults', [
                ...$config->getAttribute('gcm_defaults'),
                'ad_storage' => 'granted',
            ]);
        });

        $diff = BannerConfigDiffer::diff($published, $draft);

        $this->assertTrue($diff['isMaterial']);
        $changedFields = array_column($diff['changes'], 'field');
        $this->assertContains('gcm_defaults.ad_storage', $changedFields);
    }

    #[Test]
    public function flags_behaviour_flags_correctly(): void
    {
        $published = $this->baseConfig();
        $draft = $this->draftFrom($published, static function (BannerConfig $config): void {
            $config->setAttribute('behaviour', [
                ...$config->getAttribute('behaviour'),
                'crossSubdomainConsent' => true,
                'rePromptAfterDays' => 180,
            ]);
        });

        $diff = BannerConfigDiffer::diff($published, $draft);

        $cross = collect($diff['changes'])->firstWhere('field', 'behaviour.crossSubdomainConsent');
        $rePrompt = collect($diff['changes'])->firstWhere('field', 'behaviour.rePromptAfterDays');

        $this->assertSame('material', $cross['category'] ?? null);
        $this->assertSame('cosmetic', $rePrompt['category'] ?? null);
        $this->assertTrue($diff['isMaterial']);
    }

    #[Test]
    public function colour_only_changes_are_cosmetic(): void
    {
        $published = $this->baseConfig();
        $draft = $this->draftFrom($published, static function (BannerConfig $config): void {
            $config->setAttribute('colors', [
                ...$config->getAttribute('colors'),
                'primary' => '#000000',
            ]);
        });

        $diff = BannerConfigDiffer::diff($published, $draft);

        $this->assertTrue($diff['hasChanges']);
        $this->assertFalse($diff['isMaterial']);
        $this->assertSame('cosmetic', $diff['changes'][0]['category']);
        $this->assertSame('colors', $diff['changes'][0]['field']);
    }

    #[Test]
    public function locales_enabled_diff_ignores_order(): void
    {
        $published = $this->baseConfig();
        $published->setAttribute('locales_enabled', ['en', 'fr', 'de']);
        $draft = $this->draftFrom($published, static function (BannerConfig $config): void {
            // Same set, different order — should NOT show up.
            $config->setAttribute('locales_enabled', ['de', 'en', 'fr']);
        });

        $diff = BannerConfigDiffer::diff($published, $draft);

        $this->assertFalse($diff['hasChanges']);
    }

    #[Test]
    public function locales_enabled_addition_is_material(): void
    {
        $published = $this->baseConfig();
        $published->setAttribute('locales_enabled', ['en']);
        $draft = $this->draftFrom($published, static function (BannerConfig $config): void {
            $config->setAttribute('locales_enabled', ['en', 'fr']);
        });

        $diff = BannerConfigDiffer::diff($published, $draft);

        $this->assertTrue($diff['isMaterial']);
        $change = collect($diff['changes'])->firstWhere('field', 'locales_enabled');
        $this->assertNotNull($change);
        $this->assertSame('material', $change['category']);
    }
}
