<?php

declare(strict_types=1);

namespace App\Support\Cookies;

/**
 * Glob-style matcher used to decide whether an observed cookie name
 * (`_ga_ABC123`) is covered by a stored pattern (`_ga_*`).
 *
 * Backed by PHP's fnmatch — supports `*` (any run of chars), `?` (single
 * char), and `[abc]` character classes. Case-sensitive: cookie names are
 * per RFC 6265.
 */
final class CookiePatternMatcher
{
    public static function matches(string $pattern, string $cookieName): bool
    {
        return fnmatch($pattern, $cookieName);
    }
}
