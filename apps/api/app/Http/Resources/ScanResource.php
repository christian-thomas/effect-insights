<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Scan
 */
class ScanResource extends JsonResource
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
            'startedAt' => $this->getAttribute('started_at')?->toIso8601String(),
            'finishedAt' => $this->getAttribute('finished_at')?->toIso8601String(),
            'errorLog' => $this->getAttribute('error_log'),
            'createdAt' => $this->getAttribute('created_at')?->toIso8601String(),
        ];
    }
}
