<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Domains;

use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use App\Models\Domain;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListDomains extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return DomainResource::collection(
            Domain::query()->orderBy('fqdn')->get(),
        );
    }
}
