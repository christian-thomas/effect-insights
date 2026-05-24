<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A single axe-core crawl across a domain's discovered URLs. Sibling to
 * {@see Scan} (cookie scanner); intentionally separate so neither table
 * has nullable columns that only apply to the other vertical.
 *
 * Aggregate counters (`violations_total`, `violations_by_impact`) are
 * denormalised so the admin index page doesn't have to count rows on
 * every render.
 */
#[Fillable([
    'domain_id', 'organisation_id', 'status', 'trigger',
    'urls_discovered', 'urls_scanned',
    'violations_total', 'violations_by_impact',
    'started_at', 'finished_at',
    'error_log', 'created_by_user_id',
])]
class AccessibilityScan extends Model
{
    use BelongsToOrganisation, HasUuids;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => ScanStatus::class,
            'trigger' => ScanTrigger::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'violations_by_impact' => 'array',
            'error_log' => 'array',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (AccessibilityScan $scan): void {
            $domainId = $scan->getAttribute('domain_id');

            if (! $domainId) {
                throw new RuntimeException('AccessibilityScan requires a domain_id');
            }

            $domain = Domain::query()->withoutGlobalScope('organisation')->find($domainId);

            if ($domain === null) {
                throw new RuntimeException(sprintf('Domain %s not found', $domainId));
            }

            // Denormalised so BelongsToOrganisation can scope without
            // joining through `domains` on every query.
            $scan->setAttribute('organisation_id', $domain->getAttribute('organisation_id'));
        });
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return HasMany<AccessibilityFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(AccessibilityFinding::class);
    }
}
