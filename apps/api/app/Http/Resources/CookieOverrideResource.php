<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CookieOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CookieOverride
 */
class CookieOverrideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getAttribute('id'),
            'organisationId' => $this->getAttribute('organisation_id'),
            'domainId' => $this->getAttribute('domain_id'),
            'namePattern' => $this->getAttribute('name_pattern'),
            'category' => $this->getAttribute('category'),
            'purpose' => $this->getAttribute('purpose'),
            'justification' => $this->getAttribute('justification'),
            'createdByUserId' => $this->getAttribute('created_by_user_id'),
            'createdAt' => $this->getAttribute('created_at')?->toIso8601String(),
            'updatedAt' => $this->getAttribute('updated_at')?->toIso8601String(),
        ];
    }
}
