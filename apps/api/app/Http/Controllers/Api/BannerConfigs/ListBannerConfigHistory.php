<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\BannerConfigs;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerConfigResource;
use App\Models\BannerConfig;
use App\Models\Domain;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lists every published banner_configs row for a domain, newest first.
 * Powers the admin's history panel — operators can browse what shipped
 * when, and rollback via {@see RollbackBannerConfig}.
 *
 * Drafts are deliberately excluded (the editor already exposes the
 * single current draft). Only published rows + previous published rows
 * (which become history when superseded) are returned.
 */
class ListBannerConfigHistory extends Controller
{
    public function __invoke(Domain $domain): AnonymousResourceCollection
    {
        $configs = BannerConfig::query()
            ->where('domain_id', $domain->getKey())
            ->where('is_draft', false)
            ->orderByDesc('version')
            ->get();

        return BannerConfigResource::collection($configs);
    }
}
