<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\BannerConfigs;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerConfigResource;
use App\Models\BannerConfig;
use App\Models\Domain;
use App\Services\BannerConfigService;
use Illuminate\Http\JsonResponse;

class ShowBannerConfig extends Controller
{
    public function __invoke(Domain $domain, BannerConfigService $service): JsonResponse
    {
        // Ensure a draft exists so the editor always has something to bind to.
        $draft = $service->getOrCreateDraft($domain);

        $published = BannerConfig::query()
            ->where('domain_id', $domain->getKey())
            ->where('is_published', true)
            ->orderByDesc('version')
            ->first();

        return response()->json([
            'draft' => BannerConfigResource::make($draft),
            'published' => $published === null
                ? null
                : BannerConfigResource::make($published),
        ]);
    }
}
