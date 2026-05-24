<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BannerConfig;

/**
 * Computes a field-by-field diff between a draft and the currently
 * published banner config. Powers the "Pending changes" preview in the
 * admin so operators can see what's about to change AND whether any of
 * it will force every consented visitor to re-prompt.
 *
 * Classification mirrors {@see MaterialChangeDetector} exactly — keep
 * the two in lock-step. Anything tagged `material` is what the
 * detector would flip on; `cosmetic` is what it would let pass.
 */
final class BannerConfigDiffer
{
    private const string MATERIAL = 'material';

    private const string COSMETIC = 'cosmetic';

    /**
     * @return array{
     *   hasChanges: bool,
     *   isMaterial: bool,
     *   isFirstPublish: bool,
     *   changes: array<int, array{
     *     field: string,
     *     category: 'material'|'cosmetic',
     *     previous: mixed,
     *     next: mixed,
     *     reason: string,
     *   }>,
     * }
     */
    public static function diff(?BannerConfig $previous, BannerConfig $next): array
    {
        if (! $previous instanceof BannerConfig) {
            return [
                'hasChanges' => true,
                'isMaterial' => true,
                'isFirstPublish' => true,
                'changes' => [],
            ];
        }

        $changes = array_merge(
            self::diffGcmDefaults($previous, $next),
            self::diffGeoRules($previous, $next),
            self::diffBehaviour($previous, $next),
            self::diffLocalesEnabled($previous, $next),
            self::diffCosmeticScalars($previous, $next),
            self::diffCosmeticStructures($previous, $next),
        );

        $hasChanges = $changes !== [];
        $isMaterial = false;
        foreach ($changes as $change) {
            if ($change['category'] === self::MATERIAL) {
                $isMaterial = true;
                break;
            }
        }

        return [
            'hasChanges' => $hasChanges,
            'isMaterial' => $isMaterial,
            'isFirstPublish' => false,
            'changes' => $changes,
        ];
    }

    /**
     * @return array<int, array{field: string, category: 'material'|'cosmetic', previous: mixed, next: mixed, reason: string}>
     */
    private static function diffGcmDefaults(BannerConfig $previous, BannerConfig $next): array
    {
        $previousGcm = (array) $previous->getAttribute('gcm_defaults');
        $nextGcm = (array) $next->getAttribute('gcm_defaults');
        $keys = array_unique([...array_keys($previousGcm), ...array_keys($nextGcm)]);
        sort($keys);

        $changes = [];
        foreach ($keys as $key) {
            $before = $previousGcm[$key] ?? null;
            $after = $nextGcm[$key] ?? null;
            if ($before === $after) {
                continue;
            }
            $changes[] = [
                'field' => sprintf('gcm_defaults.%s', $key),
                'category' => self::MATERIAL,
                'previous' => $before,
                'next' => $after,
                'reason' => 'Changes a Google Consent Mode default signal',
            ];
        }

        return $changes;
    }

    /**
     * @return array<int, array{field: string, category: 'material'|'cosmetic', previous: mixed, next: mixed, reason: string}>
     */
    private static function diffGeoRules(BannerConfig $previous, BannerConfig $next): array
    {
        $previousRules = self::normalise((array) $previous->getAttribute('geo_rules'));
        $nextRules = self::normalise((array) $next->getAttribute('geo_rules'));

        if ($previousRules === $nextRules) {
            return [];
        }

        return [[
            'field' => 'geo_rules',
            'category' => self::MATERIAL,
            'previous' => $previousRules,
            'next' => $nextRules,
            'reason' => 'Changes which regions see the banner',
        ]];
    }

    /**
     * @return array<int, array{field: string, category: 'material'|'cosmetic', previous: mixed, next: mixed, reason: string}>
     */
    private static function diffBehaviour(BannerConfig $previous, BannerConfig $next): array
    {
        $previousBehaviour = (array) $previous->getAttribute('behaviour');
        $nextBehaviour = (array) $next->getAttribute('behaviour');

        $classifications = [
            'honourGpc' => [self::MATERIAL, 'Toggles whether Global Privacy Control is honoured'],
            'autoDenyOnDismiss' => [self::MATERIAL, 'Changes whether dismissal counts as denial'],
            'crossSubdomainConsent' => [self::MATERIAL, 'Changes whether consent is shared across subdomains'],
            'rePromptAfterDays' => [self::COSMETIC, 'Changes cookie lifetime, not what is consented to'],
        ];

        $changes = [];
        foreach ($classifications as $key => [$category, $reason]) {
            $before = $previousBehaviour[$key] ?? null;
            $after = $nextBehaviour[$key] ?? null;
            if ($before === $after) {
                continue;
            }
            $changes[] = [
                'field' => sprintf('behaviour.%s', $key),
                'category' => $category,
                'previous' => $before,
                'next' => $after,
                'reason' => $reason,
            ];
        }

        return $changes;
    }

    /**
     * @return array<int, array{field: string, category: 'material'|'cosmetic', previous: mixed, next: mixed, reason: string}>
     */
    private static function diffLocalesEnabled(BannerConfig $previous, BannerConfig $next): array
    {
        $previousLocales = (array) $previous->getAttribute('locales_enabled');
        $nextLocales = (array) $next->getAttribute('locales_enabled');
        sort($previousLocales);
        sort($nextLocales);

        if ($previousLocales === $nextLocales) {
            return [];
        }

        return [[
            'field' => 'locales_enabled',
            'category' => self::MATERIAL,
            'previous' => $previousLocales,
            'next' => $nextLocales,
            'reason' => 'Changes which languages are offered',
        ]];
    }

    /**
     * @return array<int, array{field: string, category: 'material'|'cosmetic', previous: mixed, next: mixed, reason: string}>
     */
    private static function diffCosmeticScalars(BannerConfig $previous, BannerConfig $next): array
    {
        $classifications = [
            'layout' => 'Changes the banner shape',
            'granularity' => 'Switches between basic accept/reject and per-category toggles',
            'locale_default' => 'Changes the default language',
        ];

        $changes = [];
        foreach ($classifications as $field => $reason) {
            $before = $previous->getAttribute($field);
            $after = $next->getAttribute($field);
            // Enum values implement BackedEnum::value; normalise both sides.
            $before = is_object($before) && property_exists($before, 'value')
                ? $before->value
                : $before;
            $after = is_object($after) && property_exists($after, 'value')
                ? $after->value
                : $after;
            if ($before === $after) {
                continue;
            }
            $changes[] = [
                'field' => $field,
                'category' => self::COSMETIC,
                'previous' => $before,
                'next' => $after,
                'reason' => $reason,
            ];
        }

        return $changes;
    }

    /**
     * @return array<int, array{field: string, category: 'material'|'cosmetic', previous: mixed, next: mixed, reason: string}>
     */
    private static function diffCosmeticStructures(BannerConfig $previous, BannerConfig $next): array
    {
        $classifications = [
            'colors' => 'Visual restyle only',
            'copy' => 'Wording change only — meaning unchanged',
        ];

        $changes = [];
        foreach ($classifications as $field => $reason) {
            $before = self::normalise((array) $previous->getAttribute($field));
            $after = self::normalise((array) $next->getAttribute($field));
            if ($before === $after) {
                continue;
            }
            $changes[] = [
                'field' => $field,
                'category' => self::COSMETIC,
                'previous' => $before,
                'next' => $after,
                'reason' => $reason,
            ];
        }

        return $changes;
    }

    /**
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
        if (! array_is_list($normalised)) {
            ksort($normalised);
        }

        return $normalised;
    }
}
