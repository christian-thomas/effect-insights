<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Domain
 */
class DomainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getAttribute('id'),
            'fqdn' => $this->getAttribute('fqdn'),
            'apexDomain' => $this->getAttribute('apex_domain'),
            'status' => $this->getAttribute('status'),
            'scanCadence' => $this->getAttribute('scan_cadence'),
            'lastScannedAt' => $this->getAttribute('last_scanned_at')?->toIso8601String(),
            'a11yScanCadence' => $this->getAttribute('a11y_scan_cadence'),
            'lastA11yScannedAt' => $this->getAttribute('last_a11y_scanned_at')?->toIso8601String(),
            'publicKey' => $this->getAttribute('public_key'),
            'settings' => $this->getAttribute('settings'),
            'createdAt' => $this->getAttribute('created_at')?->toIso8601String(),
            'updatedAt' => $this->getAttribute('updated_at')?->toIso8601String(),
        ];
    }
}
