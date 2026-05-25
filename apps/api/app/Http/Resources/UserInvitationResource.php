<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserInvitation
 */
class UserInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getAttribute('id'),
            'email' => $this->getAttribute('email'),
            'role' => $this->getAttribute('role')->value,
            'expiresAt' => $this->getAttribute('expires_at')?->toIso8601String(),
            'acceptedAt' => $this->getAttribute('accepted_at')?->toIso8601String(),
            'createdAt' => $this->getAttribute('created_at')?->toIso8601String(),
            'invitedBy' => $this->whenLoaded('invitedBy', fn (): array => [
                'id' => $this->invitedBy->getKey(),
                'name' => $this->invitedBy->getAttribute('name'),
            ]),
        ];
    }
}
