<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Organisation;
use App\Support\CurrentOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to any model that lives inside a tenant boundary (Domain, BannerConfig,
 * future Scan / ScanFinding / ConsentEventArchive / etc).
 *
 * Behaviour:
 *  - Global scope filters queries to the current organisation when one is bound.
 *  - When no current organisation is bound (console, queues, seeders,
 *    pre-middleware test setup) the scope is a no-op — escape hatch for
 *    legitimate cross-tenant work without scattering withoutGlobalScope() calls.
 *  - On `creating`, auto-fills `organisation_id` from the current organisation
 *    so controllers stop forgetting it.
 */
trait BelongsToOrganisation
{
    public static function bootBelongsToOrganisation(): void
    {
        static::addGlobalScope('organisation', function (Builder $query): void {
            $current = app(CurrentOrganisation::class);

            if (! $current->isSet()) {
                return;
            }

            $column = $query->getModel()->qualifyColumn('organisation_id');
            $query->where($column, $current->id());
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('organisation_id')) {
                return;
            }

            $current = app(CurrentOrganisation::class);

            if ($current->isSet()) {
                $model->setAttribute('organisation_id', $current->id());
            }
        });
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
