<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\ConsentEvents;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConsentEventResource;
use App\Models\ConsentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Paginated consent log query. Always tenant-scoped via the global scope on
 * ConsentEvent — no manual organisation_id check needed in the query
 * builder calls below.
 */
class ListConsentEvents extends Controller
{
    private const MAX_PER_PAGE = 100;

    private const DEFAULT_PER_PAGE = 25;

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = ConsentEvent::query()->orderByDesc('occurred_at');

        if ($request->filled('domain_id')) {
            $query->where('domain_id', $request->string('domain_id'));
        }

        if ($request->filled('anonymous_id')) {
            $query->where('anonymous_id', $request->string('anonymous_id'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->string('event_type'));
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->date('to'));
        }

        $perPage = min(
            self::MAX_PER_PAGE,
            max(1, (int) $request->input('per_page', self::DEFAULT_PER_PAGE)),
        );

        return ConsentEventResource::collection($query->paginate($perPage));
    }
}
