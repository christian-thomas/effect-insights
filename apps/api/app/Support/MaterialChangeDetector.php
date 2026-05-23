<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BannerConfig;

/**
 * Decides whether the new banner config differs from the previous one in
 * a way that requires the visitor to re-consent.
 *
 * Material (re-prompt): anything that changes how tracking will actually
 * run — what GCM signals fire by default, which regions see the banner,
 * whether GPC is honoured, whether dismissal counts as denial, whether
 * consent crosses subdomains, or what locales are offered.
 *
 * Cosmetic (no re-prompt): colours, copy text, layout, default locale
 * pick, and re-prompt-after-days (which only changes cookie lifetime,
 * not what the visitor is consenting to).
 */
final class MaterialChangeDetector
{
    public static function isMaterial(BannerConfig $previous, BannerConfig $next): bool
    {
        if (self::gcmDefaultsDiffer($previous, $next)) {
            return true;
        }
        if (self::geoRulesDiffer($previous, $next)) {
            return true;
        }
        if (self::complianceBehaviourDiffers($previous, $next)) {
            return true;
        }

        return self::localesEnabledDiffer($previous, $next);
    }

    private static function gcmDefaultsDiffer(BannerConfig $previous, BannerConfig $next): bool
    {
        return self::normalise($previous->getAttribute('gcm_defaults'))
            !== self::normalise($next->getAttribute('gcm_defaults'));
    }

    private static function geoRulesDiffer(BannerConfig $previous, BannerConfig $next): bool
    {
        return self::normalise($previous->getAttribute('geo_rules'))
            !== self::normalise($next->getAttribute('geo_rules'));
    }

    private static function complianceBehaviourDiffers(BannerConfig $previous, BannerConfig $next): bool
    {
        $previousBehaviour = (array) $previous->getAttribute('behaviour');
        $nextBehaviour = (array) $next->getAttribute('behaviour');

        foreach (['honourGpc', 'autoDenyOnDismiss', 'crossSubdomainConsent'] as $key) {
            if (($previousBehaviour[$key] ?? null) !== ($nextBehaviour[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private static function localesEnabledDiffer(BannerConfig $previous, BannerConfig $next): bool
    {
        $previousLocales = (array) $previous->getAttribute('locales_enabled');
        $nextLocales = (array) $next->getAttribute('locales_enabled');
        sort($previousLocales);
        sort($nextLocales);

        return $previousLocales !== $nextLocales;
    }

    /**
     * Recursively ksort arrays so equality is structure-agnostic. JSON
     * round-trips can change key order, and we don't want that to look like
     * a material change.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private static function normalise($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalised = [];
        foreach ($value as $key => $item) {
            $normalised[$key] = self::normalise($item);
        }
        ksort($normalised);

        return $normalised;
    }
}
