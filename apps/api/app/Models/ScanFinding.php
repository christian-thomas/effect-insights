<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CookieCategory;
use App\Enums\CookieResolutionSource;
use App\Enums\FindingParty;
use App\Enums\FindingType;
use App\Models\Concerns\BelongsToOrganisation;
use App\Services\Cookies\CookieCategoryResolver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Individual artefact observed during a {@see Scan} — a cookie set, a
 * script loaded, an iframe embedded, or a network request fired.
 *
 * `category` / `category_source` come from {@see CookieCategoryResolver}.
 * Both NULL means no match — surfaces in the manual review queue.
 */
#[Fillable([
    'scan_id', 'organisation_id', 'type', 'name', 'domain_set_on',
    'value_pattern', 'party', 'category', 'category_source',
    'purpose_text', 'first_seen_url', 'cookie_id', 'cookie_override_id',
    'raw_data',
])]
class ScanFinding extends Model
{
    use BelongsToOrganisation, HasUuids;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => FindingType::class,
            'party' => FindingParty::class,
            'category' => CookieCategory::class,
            'category_source' => CookieResolutionSource::class,
            'raw_data' => 'array',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (ScanFinding $finding): void {
            $scanId = $finding->getAttribute('scan_id');

            if (! $scanId) {
                throw new RuntimeException('ScanFinding requires a scan_id');
            }

            // Inherit organisation_id from the parent scan — same denormalised
            // pattern as BannerConfig + ConsentEvent.
            if (! $finding->getAttribute('organisation_id')) {
                $scan = Scan::query()->withoutGlobalScope('organisation')->find($scanId);

                if ($scan === null) {
                    throw new RuntimeException(sprintf('Scan %s not found', $scanId));
                }

                $finding->setAttribute('organisation_id', $scan->getAttribute('organisation_id'));
            }
        });
    }

    /**
     * @return BelongsTo<Scan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
