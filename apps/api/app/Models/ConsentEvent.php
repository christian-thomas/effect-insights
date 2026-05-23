<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsentEventType;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\ConsentEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable([
    'id', 'domain_id', 'organisation_id', 'banner_config_version',
    'anonymous_id', 'categories', 'gcm_signals',
    'country_code', 'user_agent_hash', 'ip_hash',
    'event_type', 'occurred_at', 'banner_text_hash',
])]
class ConsentEvent extends Model
{
    /** @use HasFactory<ConsentEventFactory> */
    use BelongsToOrganisation, HasFactory, HasUuids;

    /**
     * Edge worker generates the UUID at the moment of the user's click so we
     * preserve client-side ordering on retries. Don't override it.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Consent events are append-only — no updates after the row lands.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'event_type' => ConsentEventType::class,
            'categories' => 'array',
            'gcm_signals' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (ConsentEvent $event): void {
            $domainId = $event->getAttribute('domain_id');

            if (! $domainId) {
                throw new RuntimeException('ConsentEvent requires a domain_id');
            }

            // Match the BannerConfig integrity pattern: denormalised
            // organisation_id is always sourced from the parent domain.
            $domain = Domain::query()->withoutGlobalScope('organisation')->find($domainId);

            if ($domain === null) {
                throw new RuntimeException(sprintf('Domain %s not found', $domainId));
            }

            $event->setAttribute('organisation_id', $domain->getAttribute('organisation_id'));
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
