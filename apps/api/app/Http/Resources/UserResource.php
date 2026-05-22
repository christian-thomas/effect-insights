<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this->getAttribute('id'),
                'name' => $this->getAttribute('name'),
                'email' => $this->getAttribute('email'),
                'isSuperuser' => $this->isSuperuser,
            ],
            'organisations' => OrganisationResource::collection($this->organisations),
        ];
    }
}
