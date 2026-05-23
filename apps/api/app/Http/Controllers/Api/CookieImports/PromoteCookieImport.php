<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\CookieImports;

use App\Http\Controllers\Controller;
use App\Http\Resources\CookieResource;
use App\Models\CookieImport;
use App\Models\User;
use App\Services\Cookies\CookieImportPromoter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PromoteCookieImport extends Controller
{
    public function __invoke(
        Request $request,
        CookieImport $cookieImport,
        CookieImportPromoter $promoter,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $cookie = $promoter->promote($cookieImport, $user);

        return CookieResource::make($cookie)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
