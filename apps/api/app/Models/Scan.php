<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\ScanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A single crawl of a domain. Holds the lifecycle state + counters; the
 * actual cookies/scripts/iframes/requests detected live in {@see ScanFinding}.
 */
#[Fillable([
    'domain_id', 'organisation_id', 'status', 'trigger',
    'urls_discovered', 'urls_scanned', 'started_at', 'finished_at',
    'error_log', 'failed_urls', 'created_by_user_id',
])]
class Scan extends Model
{
    /** @use HasFactory<ScanFactory> */
    use BelongsToOrganisation, HasFactory, HasUuids;

    /**
     * In-memory defaults so a freshly-`create()`d Scan serialises with 0
     * counters rather than null — the migration defaults these columns
     * to 0 on disk, but Eloquent doesn't refresh after insert, so without
     * this the controller's `ScanResource::make($scan)` would emit
     * `urlsScanned: null` for queued rows.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'urls_discovered' => 0,
        'urls_scanned' => 0,
    ];

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
            'error_log' => 'array',
            'failed_urls' => 'array',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (Scan $scan): void {
            $domainId = $scan->getAttribute('domain_id');

            if (! $domainId) {
                throw new RuntimeException('Scan requires a domain_id');
            }

            $domain = Domain::query()->withoutGlobalScope('organisation')->find($domainId);

            if ($domain === null) {
                throw new RuntimeException(sprintf('Domain %s not found', $domainId));
            }

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
     * @return HasMany<ScanFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(ScanFinding::class);
    }
}
