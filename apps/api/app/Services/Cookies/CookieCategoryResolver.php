<?php

declare(strict_types=1);

namespace App\Services\Cookies;

use App\Enums\CookieCategory;
use App\Enums\CookieResolutionSource;
use App\Models\Cookie;
use App\Models\CookieOverride;
use App\Models\Domain;
use App\Models\Organisation;
use App\Support\Cookies\CookiePatternMatcher;
use App\Support\Cookies\CookieResolution;

/**
 * Resolves an observed cookie name to its category + purpose, walking
 * the override chain:
 *
 *   1. Domain-scoped override (organisation_id + domain_id match)
 *   2. Organisation-wide override (organisation_id match, domain_id NULL)
 *   3. Canonical cookies table
 *
 * When multiple canonical rows match (e.g. an imported `_ga_*` and a
 * manually-added one), the most recently created wins. Returns `null`
 * when nothing matches — caller decides whether to surface the cookie
 * to the manual-review queue.
 */
final class CookieCategoryResolver
{
    public function resolve(
        string $cookieName,
        ?Domain $domain = null,
        ?Organisation $organisation = null,
    ): ?CookieResolution {
        // The domain implies the organisation; allow either to be passed
        // so the resolver works in scanner contexts (domain known) and in
        // admin contexts (org known, no specific domain).
        $organisationId = $organisation?->getKey() ?? $domain?->getAttribute('organisation_id');

        if ($organisationId !== null && $domain instanceof Domain) {
            $resolution = $this->matchOverride(
                $cookieName,
                $organisationId,
                $domain->getKey(),
                CookieResolutionSource::DomainOverride,
            );

            if ($resolution instanceof CookieResolution) {
                return $resolution;
            }
        }

        if ($organisationId !== null) {
            $resolution = $this->matchOverride(
                $cookieName,
                $organisationId,
                null,
                CookieResolutionSource::OrganisationOverride,
            );

            if ($resolution instanceof CookieResolution) {
                return $resolution;
            }
        }

        return $this->matchCanonical($cookieName);
    }

    private function matchOverride(
        string $cookieName,
        string $organisationId,
        ?string $domainId,
        CookieResolutionSource $resolutionSource,
    ): ?CookieResolution {
        $query = CookieOverride::query()
            ->withoutGlobalScope('organisation')
            ->where('organisation_id', $organisationId);

        if ($domainId === null) {
            $query->whereNull('domain_id');
        } else {
            $query->where('domain_id', $domainId);
        }

        /** @var CookieOverride|null $match */
        $match = $query
            ->get()
            ->first(static fn (CookieOverride $override): bool => CookiePatternMatcher::matches(
                (string) $override->getAttribute('name_pattern'),
                $cookieName,
            ));

        if ($match === null) {
            return null;
        }

        /** @var CookieCategory $category */
        $category = $match->getAttribute('category');

        return new CookieResolution(
            cookieName: $cookieName,
            matchedPattern: (string) $match->getAttribute('name_pattern'),
            category: $category,
            purpose: $match->getAttribute('purpose'),
            source: $resolutionSource,
            overrideId: (string) $match->getKey(),
        );
    }

    private function matchCanonical(string $cookieName): ?CookieResolution
    {
        /** @var Cookie|null $match */
        $match = Cookie::query()->latest()
            ->get()
            ->first(static fn (Cookie $cookie): bool => CookiePatternMatcher::matches(
                (string) $cookie->getAttribute('name_pattern'),
                $cookieName,
            ));

        if ($match === null) {
            return null;
        }

        /** @var CookieCategory $category */
        $category = $match->getAttribute('category');

        return new CookieResolution(
            cookieName: $cookieName,
            matchedPattern: (string) $match->getAttribute('name_pattern'),
            category: $category,
            purpose: (string) $match->getAttribute('purpose'),
            source: CookieResolutionSource::Canonical,
            cookieId: (string) $match->getKey(),
        );
    }
}
