<?php

declare(strict_types=1);

namespace App\Actions\ConsentEvents;

use App\Http\Requests\StoreConsentEventRequest;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\ConsentEvent;
use App\Models\Domain;

/**
 * Mirrors a consent event row written by the edge worker (D1) into
 * Laravel's MySQL store so the consent log + receipt exports + nightly
 * archive can run off a single source. Idempotent on the event UUID —
 * the edge worker retries are safe to land twice.
 *
 * Bypasses the {@see BelongsToOrganisation} global
 * scope because the writer is unauthenticated (edge worker → shared
 * secret) and the tenant is derived from the domain public key, not the
 * session.
 */
final readonly class IngestConsentEvent
{
    /**
     * @param  array<string, mixed>  $data  Validated payload from {@see StoreConsentEventRequest}.
     */
    public function __invoke(array $data): void
    {
        $domain = Domain::query()
            ->withoutGlobalScope('organisation')
            ->where('public_key', $data['domainPublicKey'])
            ->firstOrFail();

        ConsentEvent::query()
            ->withoutGlobalScope('organisation')
            ->updateOrCreate(
                ['id' => $data['id']],
                [
                    'domain_id' => $domain->getKey(),
                    'banner_config_version' => $data['bannerConfigVersion'],
                    'anonymous_id' => $data['anonymousId'],
                    'categories' => $data['categories'],
                    'gcm_signals' => $data['gcmSignals'],
                    'country_code' => $data['countryCode'] ?? null,
                    'user_agent_hash' => $data['userAgentHash'],
                    'ip_hash' => $data['ipHash'],
                    'event_type' => $data['eventType'],
                    'occurred_at' => $data['occurredAt'],
                    'banner_text_hash' => $data['bannerTextHash'],
                ],
            );
    }
}
