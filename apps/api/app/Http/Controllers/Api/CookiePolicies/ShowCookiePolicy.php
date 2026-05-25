<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\CookiePolicies;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Cookies\CookiePolicyBuilder;
use Illuminate\Http\JsonResponse;

/**
 * Tenant-scoped JSON view of the cookie policy. Powers the admin
 * preview pane + lets clients pull the structured data into their own
 * privacy-page templates if they don't want to embed the public HTML.
 */
class ShowCookiePolicy extends Controller
{
    public function __construct(
        private readonly CookiePolicyBuilder $builder,
    ) {}

    public function __invoke(Domain $domain): JsonResponse
    {
        return new JsonResponse([
            'data' => $this->builder->build($domain),
        ]);
    }
}
