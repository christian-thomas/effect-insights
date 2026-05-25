<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\A11yImpact;
use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One axe-core rule violation observed during a scan. The same rule can
 * fire multiple times across a domain — one row per (scan, url, rule_id)
 * combination. Per-element details (CSS target, HTML, axe's failure
 * summary) live inside `nodes` rather than as columns, since the array
 * length varies wildly between rules.
 */
#[Fillable([
    'accessibility_scan_id', 'organisation_id',
    'rule_id', 'impact', 'description', 'help_text', 'help_url',
    'url', 'tags', 'nodes',
])]
class AccessibilityFinding extends Model
{
    use BelongsToOrganisation, HasUuids;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'impact' => A11yImpact::class,
            'tags' => 'array',
            'nodes' => 'array',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (AccessibilityFinding $finding): void {
            $scanId = $finding->getAttribute('accessibility_scan_id');

            if (! $scanId) {
                throw new RuntimeException('AccessibilityFinding requires an accessibility_scan_id');
            }

            if (! $finding->getAttribute('organisation_id')) {
                $scan = AccessibilityScan::query()
                    ->withoutGlobalScope('organisation')
                    ->find($scanId);

                if ($scan === null) {
                    throw new RuntimeException(sprintf('AccessibilityScan %s not found', $scanId));
                }

                $finding->setAttribute('organisation_id', $scan->getAttribute('organisation_id'));
            }
        });
    }

    /**
     * @return BelongsTo<AccessibilityScan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(AccessibilityScan::class, 'accessibility_scan_id');
    }

    /**
     * Order findings most-severe-first (Critical → Serious → Moderate →
     * Minor → unknown). CASE WHEN works in both MySQL (prod) and SQLite
     * (tests). FIELD() would be MySQL-only.
     *
     * @param  Builder<AccessibilityFinding>  $query
     * @return Builder<AccessibilityFinding>
     */
    protected function scopeOrderByImpact(Builder $query): Builder
    {
        $expression = sprintf(
            "CASE impact WHEN '%s' THEN 1 WHEN '%s' THEN 2 WHEN '%s' THEN 3 WHEN '%s' THEN 4 ELSE 5 END",
            A11yImpact::Critical->value,
            A11yImpact::Serious->value,
            A11yImpact::Moderate->value,
            A11yImpact::Minor->value,
        );

        return $query->orderByRaw($expression);
    }
}
