<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Cookie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cookie
 */
class CookieResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getAttribute('id'),
            'namePattern' => $this->getAttribute('name_pattern'),
            'vendor' => $this->getAttribute('vendor'),
            'vendorDomain' => $this->getAttribute('vendor_domain'),
            'category' => $this->getAttribute('category'),
            'purpose' => $this->getAttribute('purpose'),
            'expiryHint' => $this->getAttribute('expiry_hint'),
            'source' => $this->getAttribute('source'),
            'sourceVersion' => $this->getAttribute('source_version'),
            'createdAt' => $this->getAttribute('created_at')?->toIso8601String(),
        ];
    }
}
