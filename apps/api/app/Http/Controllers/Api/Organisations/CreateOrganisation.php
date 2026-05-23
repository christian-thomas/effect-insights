<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organisations;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganisationRequest;
use App\Http\Resources\OrganisationResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CreateOrganisation extends Controller
{
    public function __invoke(StoreOrganisationRequest $request): JsonResponse
    {
        return OrganisationResource::make($request->persist())
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
