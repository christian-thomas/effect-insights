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
 * packages/types/src/banner.ts — if you change one, change the other.
 *
 * Fields the v0 editor doesn't yet expose (locales, granularity, geo,
 * GCM defaults, behaviour) are filled with safe defaults here. When those
 * UI fields land in iteration 5, the builder should read them off the row
 * instead.
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
            'localeDefault' => 'en',
            'localesEnabled' => ['en'],
            'layout' => (string) $bannerConfig->getAttribute('layout'),
            'granularity' => 'categorised',
            'colors' => (array) $bannerConfig->getAttribute('colors'),
            'copy' => self::buildCopyByLocale((array) $bannerConfig->getAttribute('copy')),
            'geoRules' => self::defaultGeoRules(),
            'gcmDefaults' => self::defaultGcmDefaults(),
            'behaviour' => self::defaultBehaviour(),
            'publishedAt' => $bannerConfig->getAttribute('published_at')?->toIso8601String() ?? now()->toIso8601String(),
            // HMAC signing lands in iteration 5; until then the marker is explicit.
            'signature' => 'unsigned',
        ];
    }

    /**
     * The wire format keys copy by locale: { en: {...}, fr: {...} }. Until
     * the locales tabs land in the editor we publish under a single locale.
     *
     * @param  array<string, mixed>  $singleLocaleCopy
     * @return array<string, mixed>
     */
    private static function buildCopyByLocale(array $singleLocaleCopy): array
    {
        return [
            'en' => $singleLocaleCopy,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultGeoRules(): array
    {
        return ['mode' => 'always'];
    }

    /**
     * GDPR-compliant defaults: deny everything except the storage Google itself
     * mandates as "essential to the site working at all" (functionality +
     * security). MUST NOT default to granted — that's the whole point of
     * Consent Mode v2.
     *
     * @return array<string, string>
     */
    private static function defaultGcmDefaults(): array
    {
        return [
            'ad_storage' => 'denied',
            'analytics_storage' => 'denied',
            'ad_user_data' => 'denied',
            'ad_personalization' => 'denied',
            'functionality_storage' => 'granted',
            'personalization_storage' => 'denied',
            'security_storage' => 'granted',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultBehaviour(): array
    {
        return [
            'rePromptAfterDays' => 365,
            'honourGpc' => true,
            'autoDenyOnDismiss' => false,
            'crossSubdomainConsent' => false,
        ];
    }
}
