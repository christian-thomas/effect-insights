<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Cookies\CookiePatternMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CookiePatternMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function patterns(): iterable
    {
        // Literal match
        yield 'exact match' => ['_fbp', '_fbp', true];
        yield 'literal mismatch' => ['_fbp', '_fbc', false];

        // Wildcard
        yield 'wildcard suffix' => ['_ga_*', '_ga_ABC123', true];
        yield 'wildcard suffix no underscore' => ['_ga_*', '_ga_', true];
        yield 'wildcard does not match shorter prefix' => ['_ga_*', '_ga', false];
        yield 'unrelated cookie does not match' => ['_ga_*', '_fbp', false];

        // Multiple wildcards
        yield 'middle wildcard' => ['intercom-*-app', 'intercom-id-app', true];

        // Case sensitivity (RFC 6265 — names are case-sensitive)
        yield 'case-sensitive miss' => ['_FBP', '_fbp', false];

        // Prefix wildcard at start
        yield 'prefix wildcard' => ['*-session', 'intercom-session', true];
    }

    #[Test]
    #[DataProvider('patterns')]
    public function fnmatch_glob_handles_each_case(string $pattern, string $name, bool $expected): void
    {
        $this->assertSame($expected, CookiePatternMatcher::matches($pattern, $name));
    }
}
