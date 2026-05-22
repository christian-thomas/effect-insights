<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\BannerConfigs;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBannerDraftRequest;
use App\Http\Resources\BannerConfigResource;
use App\Models\Domain;
use App\Services\BannerConfigService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UpdateBannerDraft extends Controller
{
    public function __invoke(
        UpdateBannerDraftRequest $request,
        Domain $domain,
        BannerConfigService $service,
    ): JsonResponse {
        $draft = $service->updateDraft($domain, $request->validated());

        // PUT semantics — always 200, regardless of whether the underlying
        // row was just created. JsonResource's automatic 201-on-create
        // doesn't fit an upsert endpoint.
        return BannerConfigResource::make($draft)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
