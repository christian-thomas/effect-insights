<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BannerLayout;
use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable([
    'domain_id', 'organisation_id', 'version', 'material_version',
    'is_draft', 'is_published',
    'layout', 'locale_default', 'locales_enabled', 'colors', 'copy',
    'gcm_defaults', 'geo_rules', 'behaviour',
    'created_by_user_id', 'published_at',
])]
class BannerConfig extends Model
{
    use BelongsToOrganisation, HasUuids;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'layout' => BannerLayout::class,
            'locales_enabled' => 'array',
            'colors' => 'array',
            'copy' => 'array',
            'gcm_defaults' => 'array',
            'geo_rules' => 'array',
            'behaviour' => 'array',
            'is_draft' => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    #[\Override]
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
