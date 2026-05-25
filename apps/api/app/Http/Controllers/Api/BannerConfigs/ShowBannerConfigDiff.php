<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\BannerConfigs;

use App\Http\Controllers\Controller;
use App\Models\BannerConfig;
use App\Models\Domain;
use App\Services\BannerConfigService;
use App\Support\BannerConfigDiffer;
use Illuminate\Http\JsonResponse;

/**
 * Returns the per-field diff between the draft and the currently
 * published banner config for `$domain`. Powers the "Pending changes"
 * panel in the admin banner editor.
 */
class ShowBannerConfigDiff extends Controller
{
    public function __invoke(Domain $domain, BannerConfigService $service): JsonResponse
    {
        $draft = $service->getOrCreateDraft($domain);

        $published = BannerConfig::query()
            ->where('domain_id', $domain->getKey())
            ->where('is_published', true)
            ->orderByDesc('version')
            ->first();

        return new JsonResponse([
            'data' => BannerConfigDiffer::diff($published, $draft),
        ]);
    }
}
