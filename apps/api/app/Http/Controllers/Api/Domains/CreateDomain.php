<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Domains;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDomainRequest;
use App\Http\Resources\DomainResource;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CreateDomain extends Controller
{
    public function __invoke(StoreDomainRequest $request): JsonResponse
    {
        // refresh() pulls the database defaults (status = 'pending', etc.) into
        // the in-memory model so the API response reflects the persisted row.
        $domain = Domain::create($request->validated())->refresh();

        return DomainResource::make($domain)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
