<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\BannerConfigs;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerConfigResource;
use App\Models\BannerConfig;
use App\Models\Domain;
use App\Services\BannerConfigService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Restores a historic banner_configs row by copying its content into
 * the current draft. Field-mapping + lookup live in
 * {@see BannerConfigService::rollbackTo}.
 */
class RollbackBannerConfig extends Controller
{
    public function __construct(
        private readonly BannerConfigService $bannerConfigs,
    ) {}

    public function __invoke(Request $request, Domain $domain): BannerConfigResource
    {
        $version = (int) $request->input('version');
        abort_if($version <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'version must be a positive integer');

        $updated = $this->bannerConfigs->rollbackTo($domain, $version);

        if (! $updated instanceof BannerConfig) {
            throw new NotFoundHttpException(sprintf(
                'No banner_configs row for domain %s at version %d',
                $domain->getKey(),
                $version,
            ));
        }

        return BannerConfigResource::make($updated);
    }
}
