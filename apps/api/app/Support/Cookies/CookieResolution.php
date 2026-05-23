<?php

declare(strict_types=1);

namespace App\Support\Cookies;

use App\Enums\CookieCategory;
use App\Enums\CookieResolutionSource;

/**
 * The outcome of looking up an observed cookie name against the canonical
 * DB + org/domain overrides. `source` tells you where the category came
 * from, which the admin UI surfaces so reviewers know whether they're
 * looking at canonical data, their own org override, or a domain-scoped one.
 */
final readonly class CookieResolution
{
    public function __construct(
        public string $cookieName,
        public string $matchedPattern,
        public CookieCategory $category,
        public ?string $purpose,
        public CookieResolutionSource $source,
        public ?string $cookieId = null,
        public ?string $overrideId = null,
    ) {}
}
