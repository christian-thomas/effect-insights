<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\ConsentEvents;

use App\Http\Concerns\ClampsPerPage;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConsentEvents\ListConsentEventsRequest;
use App\Http\Resources\ConsentEventResource;
use App\Models\ConsentEvent;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Paginated consent log query. Always tenant-scoped via the global scope on
 * ConsentEvent — no manual organisation_id check needed since the FormRequest
 * + global scope handle isolation.
 */
class ListConsentEvents extends Controller
{
    use ClampsPerPage;

    public function __invoke(ListConsentEventsRequest $request): AnonymousResourceCollection
    {
        return ConsentEventResource::collection(
            $request->applyFilters(ConsentEvent::query())
                ->paginate($this->resolvePerPage($request, default: 25)),
        );
    }
}
