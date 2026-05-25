<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Cookies;

use App\Http\Concerns\ClampsPerPage;
use App\Http\Controllers\Controller;
use App\Http\Resources\CookieResource;
use App\Models\Cookie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListCookies extends Controller
{
    use ClampsPerPage;

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = Cookie::query()
            ->orderBy('vendor')
            ->orderBy('name_pattern');

        $search = $request->string('search')->trim()->toString();
        if ($search !== '') {
            $like = sprintf('%%%s%%', $search);
            $query->where(function ($builder) use ($like): void {
                $builder->where('name_pattern', 'like', $like)
                    ->orWhere('vendor', 'like', $like);
            });
        }

        $category = $request->string('category')->trim()->toString();
        if ($category !== '') {
            $query->where('category', $category);
        }

        $perPage = $this->resolvePerPage($request, default: 50);

        return CookieResource::collection($query->paginate($perPage));
    }
}
