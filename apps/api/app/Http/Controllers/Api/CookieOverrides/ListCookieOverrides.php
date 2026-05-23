<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\CookieOverrides;

use App\Http\Controllers\Controller;
use App\Http\Resources\CookieOverrideResource;
use App\Models\CookieOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListCookieOverrides extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = CookieOverride::query()->latest('updated_at');

        $domainId = $request->string('domain_id')->trim()->toString();
        if ($domainId !== '') {
            // Caller-supplied filter. Allow `org` as a synonym for the
            // org-wide bucket (domain_id IS NULL).
            if ($domainId === 'org') {
                $query->whereNull('domain_id');
            } else {
                $query->where('domain_id', $domainId);
            }
        }

        return CookieOverrideResource::collection($query->paginate(50));
    }
}
