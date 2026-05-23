<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ScanFinding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScanFinding
 */
class ScanFindingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getAttribute('id'),
            'scanId' => $this->getAttribute('scan_id'),
            'type' => $this->getAttribute('type'),
            'name' => $this->getAttribute('name'),
            'domainSetOn' => $this->getAttribute('domain_set_on'),
            'party' => $this->getAttribute('party'),
            'category' => $this->getAttribute('category'),
            'categorySource' => $this->getAttribute('category_source'),
            'purposeText' => $this->getAttribute('purpose_text'),
            'firstSeenUrl' => $this->getAttribute('first_seen_url'),
            'cookieId' => $this->getAttribute('cookie_id'),
            'cookieOverrideId' => $this->getAttribute('cookie_override_id'),
        ];
    }
}
