<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organisations;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganisationResource;
use App\Models\Organisation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListOrganisations extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return OrganisationResource::collection(
            Organisation::query()->orderBy('name')->get(),
        );
    }
}
