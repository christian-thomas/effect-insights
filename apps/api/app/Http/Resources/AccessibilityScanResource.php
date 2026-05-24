<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AccessibilityScan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccessibilityScan
 */
class AccessibilityScanResource extends JsonResource
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
            'status' => $this->getAttribute('status'),
            'trigger' => $this->getAttribute('trigger'),
            'urlsDiscovered' => $this->getAttribute('urls_discovered'),
            'urlsScanned' => $this->getAttribute('urls_scanned'),
            'violationsTotal' => $this->getAttribute('violations_total'),
            'violationsByImpact' => $this->getAttribute('violations_by_impact'),
            'startedAt' => $this->getAttribute('started_at')?->toIso8601String(),
            'finishedAt' => $this->getAttribute('finished_at')?->toIso8601String(),
            'errorLog' => $this->getAttribute('error_log'),
            'createdAt' => $this->getAttribute('created_at')?->toIso8601String(),
        ];
    }
}
