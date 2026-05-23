<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\CookieImports;

use App\Http\Controllers\Controller;
use App\Http\Resources\CookieImportResource;
use App\Models\CookieImport;
use App\Models\User;
use App\Services\Cookies\CookieImportPromoter;
use Illuminate\Http\Request;

class RejectCookieImport extends Controller
{
    public function __invoke(
        Request $request,
        CookieImport $cookieImport,
        CookieImportPromoter $promoter,
    ): CookieImportResource {
        /** @var User $user */
        $user = $request->user();

        return CookieImportResource::make($promoter->reject($cookieImport, $user));
    }
}
