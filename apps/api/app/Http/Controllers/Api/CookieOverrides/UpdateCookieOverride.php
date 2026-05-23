<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\CookieOverrides;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCookieOverrideRequest;
use App\Http\Resources\CookieOverrideResource;
use App\Models\CookieOverride;

class UpdateCookieOverride extends Controller
{
    public function __invoke(
        UpdateCookieOverrideRequest $request,
        CookieOverride $cookieOverride,
    ): CookieOverrideResource {
        return CookieOverrideResource::make($request->persist());
    }
}
