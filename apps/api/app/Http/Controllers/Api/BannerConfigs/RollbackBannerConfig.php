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
 * the current draft. Doesn't publish — leaves the rollback in the
 * draft so the operator can review (and the existing pending-changes
 * + material-classification UI catches them) before clicking Publish.
 *
 * That gives reversibility without surprise: someone clicks "Rollback
 * to v3" → draft swaps to v3's content → admin sees a yellow
 * "material change" banner if applicable → clicks Publish to commit.
 */
class RollbackBannerConfig extends Controller
{
    public function __construct(
        private readonly BannerConfigService $bannerConfigs,
    ) {}

    public function __invoke(Request $request, Domain $domain): BannerConfigResource
    {
        $version = (int) $request->input('version');

        if ($version <= 0) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'version must be a positive integer');
        }

        $historic = BannerConfig::query()
            ->where('domain_id', $domain->getKey())
            ->where('is_draft', false)
            ->where('version', $version)
            ->first();

        if ($historic === null) {
            throw new NotFoundHttpException(sprintf(
                'No banner_configs row for domain %s at version %d',
                $domain->getKey(),
                $version,
            ));
        }

        $updated = $this->bannerConfigs->updateDraft($domain, [
            'layout' => $historic->getAttribute('layout'),
            'granularity' => $historic->getAttribute('granularity'),
            'locale_default' => $historic->getAttribute('locale_default'),
            'locales_enabled' => $historic->getAttribute('locales_enabled'),
            'colors' => $historic->getAttribute('colors'),
            'copy' => $historic->getAttribute('copy'),
            'gcm_defaults' => $historic->getAttribute('gcm_defaults'),
            'geo_rules' => $historic->getAttribute('geo_rules'),
            'behaviour' => $historic->getAttribute('behaviour'),
        ]);

        return BannerConfigResource::make($updated);
    }
}
