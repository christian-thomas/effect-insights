<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organisations;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganisationRequest;
use App\Http\Resources\OrganisationResource;
use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CreateOrganisation extends Controller
{
    public function __invoke(StoreOrganisationRequest $request): JsonResponse
    {
        $organisation = Organisation::create($request->validated());

        return OrganisationResource::make($organisation)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
