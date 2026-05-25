<?php

declare(strict_types=1);

namespace App\Http\Requests\ConsentEvents;

use App\Enums\ConsentEventType;
use App\Models\ConsentEvent;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation + filter composition for the two endpoints that
 * read the consent log: paginated list ({@see ListConsentEvents}) and
 * streaming CSV export ({@see ExportConsentEventsCsv}). Both honour
 * the same query-string surface — `domain_id`, `anonymous_id`,
 * `event_type`, `from`, `to` — so the same FormRequest builds the
 * same Eloquent query for both.
 */
class ListConsentEventsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Domain existence is org-scoped via the global scope on Domain —
            // a UUID owned by another tenant fails this check cleanly.
            'domain_id' => ['sometimes', 'uuid', Rule::exists(Domain::class, 'id')],
            'anonymous_id' => ['sometimes', 'string', 'max:128'],
            'event_type' => ['sometimes', Rule::enum(ConsentEventType::class)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            // `per_page` is soft-clamped to [1, 100] by ClampsPerPage rather
            // than rejected at validation — operators passing per_page=500
            // get 100 silently, mirroring previous behaviour. `page` is
            // validated only by Laravel's paginate() — any value >lastPage
            // resolves to an empty page.
        ];
    }

    /**
     * Compose the filter chain onto a base ConsentEvent query. Callers
     * supply the base query (typically `ConsentEvent::query()`); this
     * adds every active filter + the canonical `latest('occurred_at')`
     * ordering used by both the list and export endpoints.
     *
     * @param  Builder<ConsentEvent>  $query
     * @return Builder<ConsentEvent>
     */
    public function applyFilters(Builder $query): Builder
    {
        return $query
            ->when(
                $this->filled('domain_id'),
                fn (Builder $query): Builder => $query->where('domain_id', $this->string('domain_id')),
            )
            ->when(
                $this->filled('anonymous_id'),
                fn (Builder $query): Builder => $query->where('anonymous_id', $this->string('anonymous_id')),
            )
            ->when(
                $this->filled('event_type'),
                fn (Builder $query): Builder => $query->where('event_type', $this->string('event_type')),
            )
            ->when(
                $this->filled('from'),
                fn (Builder $query): Builder => $query->where('occurred_at', '>=', $this->date('from')),
            )
            ->when(
                $this->filled('to'),
                fn (Builder $query): Builder => $query->where('occurred_at', '<=', $this->date('to')),
            )
            ->latest('occurred_at');
    }
}
