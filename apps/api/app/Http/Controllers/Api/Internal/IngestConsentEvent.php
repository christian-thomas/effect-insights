<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConsentEventRequest;
use App\Models\ConsentEvent;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirror endpoint for the edge consent worker. The worker writes the
 * canonical row to D1 first and fires this off as a best-effort
 * fire-and-forget — Laravel's copy is what powers the consent log viewer
 * and long-term audit (>90 days, past D1's hot window).
 *
 * Idempotent — receiving the same UUID twice is a no-op. Edge retries are
 * therefore safe.
 */
class IngestConsentEvent extends Controller
{
    public function __invoke(StoreConsentEventRequest $request): JsonResponse
    {
        $data = $request->validated();

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

        return response()->json(['ok' => true], Response::HTTP_ACCEPTED);
    }
}
