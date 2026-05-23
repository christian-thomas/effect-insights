<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\CookieOverrides;

use App\Http\Controllers\Controller;
use App\Models\CookieOverride;
use Illuminate\Http\Response;

class DeleteCookieOverride extends Controller
{
    public function __invoke(CookieOverride $cookieOverride): Response
    {
        $cookieOverride->delete();

        return response()->noContent();
    }
}
