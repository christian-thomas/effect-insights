<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organisations;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCurrentOrganisationRequest;
use App\Http\Resources\OrganisationResource;
use App\Support\CurrentOrganisation;

class UpdateCurrentOrganisation extends Controller
{
    public function __invoke(
        UpdateCurrentOrganisationRequest $request,
        CurrentOrganisation $current,
    ): OrganisationResource {
        return OrganisationResource::make($request->persist($current));
    }
}
