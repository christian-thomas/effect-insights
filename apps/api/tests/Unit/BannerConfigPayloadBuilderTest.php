<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BannerLayout;
use App\Enums\DomainStatus;
use App\Models\BannerConfig;
use App\Models\Domain;
use App\Services\BannerConfig\BannerConfigSigner;
use App\Services\BannerConfigService;
use App\Support\BannerConfigPayloadBuilder;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BannerConfigPayloadBuilderTest extends TestCase
{
    private function builder(): BannerConfigPayloadBuilder
    {
        return new BannerConfigPayloadBuilder(
            new BannerConfigSigner(key: 'unit-test-signing-key'),
        );
    }

    private function makeDomain(): Domain
    {
        $domain = new Domain([
            'fqdn' => 'effectdigital.com',
            'apex_domain' => 'effectdigital.com',
            'status' => DomainStatus::Active,
            'public_key' => 'pk_test_abc123',
        ]);
        $domain->setAttribute('id', '019e0000-0000-0000-0000-000000000001');
        $domain->setAttribute('organisation_id', '019e0000-0000-0000-0000-000000000002');

        return $domain;
    }

    private function makePublishedConfig(): BannerConfig
    {
        $config = new BannerConfig([
            'domain_id' => '019e0000-0000-0000-0000-000000000001',
            'organisation_id' => '019e0000-0000-0000-0000-000000000002',
            'version' => 3,
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
            'published_at' => CarbonImmutable::parse('2026-05-22T12:00:00Z'),
        ]);
        $config->setAttribute('id', '019e0000-0000-0000-0000-000000000003');

        return $config;
    }

    #[Test]
    public function it_refuses_to_build_a_payload_for_an_unpublished_config(): void
    {
        $domain = $this->makeDomain();
        $config = $this->makePublishedConfig();
        $config->setAttribute('is_published', false);

        $this->expectException(InvalidArgumentException::class);

        $this->builder()->build($config, $domain);
    }

    #[Test]
    public function it_includes_the_domain_public_key_and_version(): void
    {
        $payload = $this->builder()->build($this->makePublishedConfig(), $this->makeDomain());

        $this->assertSame('pk_test_abc123', $payload['domainPublicKey']);
        $this->assertSame(3, $payload['version']);
    }

    #[Test]
    public function gcm_defaults_are_deny_everything_except_functionality_and_security(): void
    {
        $payload = $this->builder()->build($this->makePublishedConfig(), $this->makeDomain());

        $this->assertSame([
            'ad_storage' => 'denied',
            'analytics_storage' => 'denied',
            'ad_user_data' => 'denied',
            'ad_personalization' => 'denied',
            'functionality_storage' => 'granted',
            'personalization_storage' => 'denied',
            'security_storage' => 'granted',
        ], $payload['gcmDefaults']);
    }

    #[Test]
    public function behaviour_defaults_re_prompt_yearly_and_honour_gpc(): void
    {
        $payload = $this->builder()->build($this->makePublishedConfig(), $this->makeDomain());

        $this->assertSame(365, $payload['behaviour']['rePromptAfterDays']);
        $this->assertTrue($payload['behaviour']['honourGpc']);
        $this->assertFalse($payload['behaviour']['autoDenyOnDismiss']);
        $this->assertFalse($payload['behaviour']['crossSubdomainConsent']);
    }

    #[Test]
    public function copy_is_keyed_by_locale(): void
    {
        $payload = $this->builder()->build($this->makePublishedConfig(), $this->makeDomain());

        $this->assertArrayHasKey('en', $payload['copy']);
        $this->assertSame('We use cookies', $payload['copy']['en']['title']);
    }

    #[Test]
    public function geo_rules_default_to_always_show(): void
    {
        $payload = $this->builder()->build($this->makePublishedConfig(), $this->makeDomain());

        $this->assertSame(['mode' => 'always', 'countries' => []], $payload['geoRules']);
    }

    #[Test]
    public function signature_is_a_64_char_hmac_hex_string(): void
    {
        $payload = $this->builder()->build($this->makePublishedConfig(), $this->makeDomain());

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['signature']);
    }

    #[Test]
    public function signature_verifies_against_the_same_key(): void
    {
        $signer = new BannerConfigSigner(key: 'unit-test-signing-key');
        $builder = new BannerConfigPayloadBuilder($signer);

        $payload = $builder->build($this->makePublishedConfig(), $this->makeDomain());

        $this->assertTrue($signer->verify($payload, (string) $payload['signature']));
    }

    #[Test]
    public function published_at_is_iso_8601(): void
    {
        $payload = $this->builder()->build($this->makePublishedConfig(), $this->makeDomain());

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            (string) $payload['publishedAt'],
        );
    }
}
