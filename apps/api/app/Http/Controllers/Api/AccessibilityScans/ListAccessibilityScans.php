<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AccessibilityScans;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessibilityScanResource;
use App\Models\AccessibilityScan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Paginated list of accessibility scans for the current organisation.
 * Mirrors {@see \App\Http\Controllers\Api\Scans\ListScans} — same
 * pagination behaviour, same `?domain_id=` filter.
 */
class ListAccessibilityScans extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = AccessibilityScan::query()->latest('created_at');

        $domainId = $request->string('domain_id')->trim()->toString();
        if ($domainId !== '') {
            $query->where('domain_id', $domainId);
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 25)));

        return AccessibilityScanResource::collection($query->paginate($perPage));
    }
}
