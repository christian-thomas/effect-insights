<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Domains;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDomainRequest;
use App\Http\Resources\DomainResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CreateDomain extends Controller
{
    public function __invoke(StoreDomainRequest $request): JsonResponse
    {
        return DomainResource::make($request->persist())
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
