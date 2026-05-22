<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\BannerConfigs;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerConfigResource;
use App\Models\Domain;
use App\Models\User;
use App\Services\BannerConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublishBannerConfig extends Controller
{
    public function __invoke(
        Request $request,
        Domain $domain,
        BannerConfigService $service,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $published = $service->publish($domain, $user);

        // Always 200 — publish mutates an existing row, the auto-201
        // resource behaviour doesn't apply semantically.
        return BannerConfigResource::make($published)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
