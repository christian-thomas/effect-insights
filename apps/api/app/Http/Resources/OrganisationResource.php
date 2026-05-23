<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organisation
 */
class OrganisationResource extends JsonResource
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
            'slug' => $this->getAttribute('slug'),
        ];
    }
}
