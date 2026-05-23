<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\CookieOverrides;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCookieOverrideRequest;
use App\Http\Resources\CookieOverrideResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CreateCookieOverride extends Controller
{
    public function __invoke(StoreCookieOverrideRequest $request): JsonResponse
    {
        return CookieOverrideResource::make($request->persist())
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
