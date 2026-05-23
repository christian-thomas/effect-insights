<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BannerConfig;
use App\Models\Domain;
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
 * creation time, not publish time.
 */
final class BannerConfigPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(BannerConfig $bannerConfig, Domain $domain): array
    {
        if (! $bannerConfig->getAttribute('is_published')) {
            throw new InvalidArgumentException('Cannot build payload for an unpublished banner config');
        }

        return [
            'domainPublicKey' => $domain->getAttribute('public_key'),
            'version' => (int) $bannerConfig->getAttribute('version'),
            'materialVersion' => (int) $bannerConfig->getAttribute('material_version'),
            'localeDefault' => (string) $bannerConfig->getAttribute('locale_default'),
            'localesEnabled' => (array) $bannerConfig->getAttribute('locales_enabled'),
            'layout' => (string) $bannerConfig->getAttribute('layout'),
            'granularity' => 'categorised',
            'colors' => (array) $bannerConfig->getAttribute('colors'),
            'copy' => (array) $bannerConfig->getAttribute('copy'),
            'geoRules' => (array) $bannerConfig->getAttribute('geo_rules'),
            'gcmDefaults' => (array) $bannerConfig->getAttribute('gcm_defaults'),
            'behaviour' => (array) $bannerConfig->getAttribute('behaviour'),
            'publishedAt' => $bannerConfig->getAttribute('published_at')?->toIso8601String() ?? now()->toIso8601String(),
            // HMAC signing lands in iteration 6; until then the marker is explicit.
            'signature' => 'unsigned',
        ];
    }
}
