<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organisations;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganisationRequest;
use App\Http\Resources\OrganisationResource;
use App\Models\Organisation;

class UpdateOrganisation extends Controller
{
    public function __invoke(UpdateOrganisationRequest $request, Organisation $organisation): OrganisationResource
    {
        return OrganisationResource::make($request->persist());
    }
}
