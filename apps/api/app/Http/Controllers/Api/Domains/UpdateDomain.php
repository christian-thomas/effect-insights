<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Domains;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDomainRequest;
use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Services\BannerConfigService;

class UpdateDomain extends Controller
{
    public function __construct(
        private readonly BannerConfigService $bannerConfigs,
    ) {}

    public function __invoke(UpdateDomainRequest $request, Domain $domain): DomainResource
    {
        $updated = $request->persist();

        // Reconcile the edge KV state with the new status — flipping a
        // domain to Paused has to actually take the banner offline, not
        // just change a column in the admin.
        $this->bannerConfigs->syncEdgeForDomain($updated);

        return DomainResource::make($updated);
    }
}
