<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable([
    'domain_id', 'organisation_id', 'version', 'is_draft', 'is_published',
    'colors', 'copy', 'layout', 'created_by_user_id', 'published_at',
])]
class BannerConfig extends Model
{
    public const LAYOUT_MODAL = 'modal';
    public const LAYOUT_BAR_TOP = 'bar-top';
    public const LAYOUT_BAR_BOTTOM = 'bar-bottom';
    public const LAYOUT_CORNER = 'corner';

    use BelongsToOrganisation, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'copy' => 'array',
            'is_draft' => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BannerConfig $bannerConfig): void {
            $domainId = $bannerConfig->getAttribute('domain_id');

            if (! $domainId) {
                throw new RuntimeException('BannerConfig requires a domain_id');
            }

            // Integrity guard: keep the denormalised organisation_id in lockstep
            // with the parent domain's organisation_id, always.
            $domain = Domain::query()->withoutGlobalScope('organisation')->find($domainId);

            if ($domain === null) {
                throw new RuntimeException(sprintf('Domain %s not found', $domainId));
            }

            $bannerConfig->setAttribute('organisation_id', $domain->getAttribute('organisation_id'));
        });
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
