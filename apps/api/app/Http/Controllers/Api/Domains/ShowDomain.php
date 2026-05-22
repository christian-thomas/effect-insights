<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Domains;

use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use App\Models\Domain;

class ShowDomain extends Controller
{
    public function __invoke(Domain $domain): DomainResource
    {
        return DomainResource::make($domain);
    }
}
