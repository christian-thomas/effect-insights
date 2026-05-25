<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Cookies;

use App\Http\Concerns\ClampsPerPage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cookies\ListCookiesRequest;
use App\Http\Resources\CookieResource;
use App\Models\Cookie;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListCookies extends Controller
{
    use ClampsPerPage;

    public function __invoke(ListCookiesRequest $request): AnonymousResourceCollection
    {
        return CookieResource::collection(
            $request->applyFilters(Cookie::query())
                ->paginate($this->resolvePerPage($request, default: 50)),
        );
    }
}
