<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ConsentEvent;
use App\Models\Domain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the body the edge consent worker posts when mirroring an event
 * from D1 to Laravel. Shape matches packages/types/src/schemas/consent.ts —
 * i.e. camelCase domainPublicKey not the internal UUID — because the edge
 * worker only knows the public_key.
 */
class StoreConsentEventRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'domainPublicKey' => ['required', 'string', 'exists:domains,public_key'],
            'bannerConfigVersion' => ['required', 'integer', 'min:0'],
            'anonymousId' => ['required', 'string', 'max:128'],
            'categories' => ['required', 'array'],
            'categories.necessary' => ['required', 'boolean'],
            'categories.preferences' => ['required', 'boolean'],
            'categories.statistics' => ['required', 'boolean'],
            'categories.marketing' => ['required', 'boolean'],
            'gcmSignals' => ['required', 'array'],
            'countryCode' => ['nullable', 'string', 'size:2'],
            'userAgentHash' => ['required', 'string', 'max:128'],
            'ipHash' => ['required', 'string', 'max:128'],
            'eventType' => ['required', Rule::in([
                ConsentEvent::EVENT_GRANTED,
                ConsentEvent::EVENT_DENIED,
                ConsentEvent::EVENT_CHANGED,
                ConsentEvent::EVENT_WITHDRAWN,
            ])],
            'occurredAt' => ['required', 'date'],
            'bannerTextHash' => ['required', 'string', 'max:128'],
        ];
    }

    /**
     * Idempotent on the event UUID — edge retries are safe.
     */
    public function persist(): ConsentEvent
    {
        $data = $this->validated();

        /** @var Domain $domain */
        $domain = Domain::query()
            ->withoutGlobalScope('organisation')
            ->where('public_key', $data['domainPublicKey'])
            ->firstOrFail();

        /** @var ConsentEvent $event */
        $event = ConsentEvent::query()
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

        return $event;
    }
}
