<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organisations;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganisationResource;
use App\Support\CurrentOrganisation;

class ShowCurrentOrganisation extends Controller
{
    public function __invoke(CurrentOrganisation $current): OrganisationResource
    {
        return OrganisationResource::make($current->getOrFail());
    }
}
