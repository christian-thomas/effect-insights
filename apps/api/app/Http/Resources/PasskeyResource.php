<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Passkey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Passkey
 */
class PasskeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getAttribute('id'),
            'name' => $this->getAttribute('name'),
            'lastUsedAt' => $this->getAttribute('last_used_at')?->toIso8601String(),
            'createdAt' => $this->getAttribute('created_at')?->toIso8601String(),
            // Deliberately NOT exposing `data` or `credential_id` — they
            // carry the public key material and per-credential id which
            // are needed server-side for verification but not user-facing.
        ];
    }
}
