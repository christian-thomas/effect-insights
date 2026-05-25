<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Scans;

use App\Http\Concerns\ClampsPerPage;
use App\Http\Controllers\Controller;
use App\Http\Resources\ScanResource;
use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListScans extends Controller
{
    use ClampsPerPage;

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = Scan::query()->latest('created_at');

        $domainId = $request->string('domain_id')->trim()->toString();
        if ($domainId !== '') {
            $query->where('domain_id', $domainId);
        }

        $perPage = $this->resolvePerPage($request, default: 25);

        return ScanResource::collection($query->paginate($perPage));
    }
}
