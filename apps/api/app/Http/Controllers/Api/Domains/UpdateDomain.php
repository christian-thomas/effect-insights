<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Domains;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDomainRequest;
use App\Http\Resources\DomainResource;
use App\Models\Domain;

class UpdateDomain extends Controller
{
    public function __invoke(UpdateDomainRequest $request, Domain $domain): DomainResource
    {
        $domain->update($request->validated());

        return DomainResource::make($domain->refresh());
    }
}
