<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AccessibilityRuleOverride;
use App\Models\Domain;
use App\Models\User;
use App\Support\CurrentOrganisation;
use Illuminate\Foundation\Http\FormRequest;

class CreateAccessibilityRuleOverrideRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rule_id' => ['required', 'string', 'max:120'],
            // Optional — when omitted, the override applies org-wide.
            'domain_id' => ['sometimes', 'nullable', 'uuid'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function persist(): AccessibilityRuleOverride
    {
        /** @var array{rule_id: string, domain_id?: string|null, reason?: string|null} $validated */
        $validated = $this->validated();

        $organisationId = resolve(CurrentOrganisation::class)->id();

        // Domain must belong to the current org if supplied.
        $domainId = $validated['domain_id'] ?? null;
        if ($domainId !== null) {
            Domain::query()->whereKey($domainId)->firstOrFail();
        }

        /** @var User $user */
        $user = $this->user();

        return AccessibilityRuleOverride::create([
            'organisation_id' => $organisationId,
            'domain_id' => $domainId,
            'rule_id' => $validated['rule_id'],
            'reason' => $validated['reason'] ?? null,
            'created_by_user_id' => $user->getKey(),
        ]);
    }
}
