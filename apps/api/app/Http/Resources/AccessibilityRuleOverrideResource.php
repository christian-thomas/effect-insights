<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AccessibilityRuleOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccessibilityRuleOverride
 */
class AccessibilityRuleOverrideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getAttribute('id'),
            'ruleId' => $this->getAttribute('rule_id'),
            'domainId' => $this->getAttribute('domain_id'),
            'reason' => $this->getAttribute('reason'),
            'createdByUserId' => $this->getAttribute('created_by_user_id'),
            'createdAt' => $this->getAttribute('created_at')?->toIso8601String(),
            'updatedAt' => $this->getAttribute('updated_at')?->toIso8601String(),
        ];
    }
}
