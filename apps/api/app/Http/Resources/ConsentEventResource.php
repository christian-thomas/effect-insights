<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ConsentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConsentEvent
 */
class ConsentEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getAttribute('id'),
            'domainId' => $this->getAttribute('domain_id'),
            'bannerConfigVersion' => $this->getAttribute('banner_config_version'),
            'anonymousId' => $this->getAttribute('anonymous_id'),
            'categories' => $this->getAttribute('categories'),
            'gcmSignals' => $this->getAttribute('gcm_signals'),
            'countryCode' => $this->getAttribute('country_code'),
            'userAgentHash' => $this->getAttribute('user_agent_hash'),
            'ipHash' => $this->getAttribute('ip_hash'),
            'eventType' => $this->getAttribute('event_type'),
            'occurredAt' => $this->getAttribute('occurred_at')?->toIso8601String(),
            'bannerTextHash' => $this->getAttribute('banner_text_hash'),
            'createdAt' => $this->getAttribute('created_at')?->toIso8601String(),
        ];
    }
}
