<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BannerConfig;
use App\Models\Domain;
use App\Services\BannerConfig\BannerConfigSigner;
use InvalidArgumentException;

/**
 * Turns a published {@see BannerConfig} + its {@see Domain} into the
 * wire-format payload that lands in Cloudflare KV and is fetched by cmp.js.
 *
 * The shape MUST match the BannerConfig interface in
 * packages/types/src/banner.ts and the zod schema in
 * packages/types/src/schemas/banner.ts — if you change one, change them all.
 *
 * Every field reads from the row directly; defaults previously baked into
 * the builder now live on the BannerConfigService and are applied at draft
 * creation time, not publish time. The trailing `signature` field is an
 * HMAC-SHA-256 the edge config worker re-computes on read to detect KV
 * tampering — see {@see BannerConfigSigner}.
 */
final readonly class BannerConfigPayloadBuilder
{
    public function __construct(
        private BannerConfigSigner $signer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(BannerConfig $bannerConfig, Domain $domain): array
    {
        if (! $bannerConfig->getAttribute('is_published')) {
            throw new InvalidArgumentException('Cannot build payload for an unpublished banner config');
        }

        $payload = $this->shape($bannerConfig, $domain);
        $payload['signature'] = $this->signer->sign($payload);

        return $payload;
    }

    /**
     * Same shape as {@see self::build()} but skips the is_published guard and
     * stamps `signature: "preview"` instead of computing an HMAC — used by
     * the admin preview iframe to render unpublished drafts. The cmp.js
     * preview mode (data-cmp-preview) bypasses signature checking anyway.
     *
     * @return array<string, mixed>
     */
    public function buildPreview(BannerConfig $bannerConfig, Domain $domain): array
    {
        $payload = $this->shape($bannerConfig, $domain);
        $payload['signature'] = 'preview';

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(BannerConfig $bannerConfig, Domain $domain): array
    {
        return [
            'domainPublicKey' => $domain->getAttribute('public_key'),
            'version' => (int) $bannerConfig->getAttribute('version'),
            'materialVersion' => (int) $bannerConfig->getAttribute('material_version'),
            'localeDefault' => (string) $bannerConfig->getAttribute('locale_default'),
            'localesEnabled' => (array) $bannerConfig->getAttribute('locales_enabled'),
            'layout' => $bannerConfig->getAttribute('layout')->value,
            'granularity' => 'categorised',
            'colors' => (array) $bannerConfig->getAttribute('colors'),
            'copy' => (array) $bannerConfig->getAttribute('copy'),
            'geoRules' => (array) $bannerConfig->getAttribute('geo_rules'),
            'gcmDefaults' => (array) $bannerConfig->getAttribute('gcm_defaults'),
            'behaviour' => (array) $bannerConfig->getAttribute('behaviour'),
            'publishedAt' => $bannerConfig->getAttribute('published_at')?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}
