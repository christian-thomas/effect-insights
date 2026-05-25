<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use Illuminate\Http\Request;

/**
 * Resolves a validated, clamped `per_page` value from the request.
 *
 * Callers declare their own constants (or use the defaults) so each
 * list endpoint can advertise its own minimum / maximum / default in
 * its class docblock, while the clamp logic itself lives in one place.
 */
trait ClampsPerPage
{
    /**
     * Parse and clamp the `per_page` query parameter.
     *
     * @param  positive-int  $default  Items per page when the parameter is absent.
     * @param  positive-int  $max  Hard ceiling (inclusive).
     */
    protected function resolvePerPage(Request $request, int $default = 25, int $max = 100): int
    {
        return max(1, min($max, (int) $request->integer('per_page', $default)));
    }
}
