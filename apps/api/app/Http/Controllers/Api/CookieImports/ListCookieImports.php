<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\CookieImports;

use App\Enums\CookieImportStatus;
use App\Http\Concerns\ClampsPerPage;
use App\Http\Controllers\Controller;
use App\Http\Resources\CookieImportResource;
use App\Models\CookieImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListCookieImports extends Controller
{
    use ClampsPerPage;

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = CookieImport::query()->latest('created_at');

        $status = $request->string('status')->trim()->toString();
        if ($status !== '') {
            $query->where('status', $status);
        } else {
            // Default to the review queue.
            $query->where('status', CookieImportStatus::Pending);
        }

        $perPage = $this->resolvePerPage($request, default: 50);

        return CookieImportResource::collection($query->paginate($perPage));
    }
}
