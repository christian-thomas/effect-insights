<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organisations;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganisationResource;
use App\Models\Organisation;

class ShowOrganisation extends Controller
{
    public function __invoke(Organisation $organisation): OrganisationResource
    {
        return OrganisationResource::make($organisation);
    }
}
