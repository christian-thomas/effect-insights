<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Support\CurrentOrganisation;
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
        $current = app(CurrentOrganisation::class);
        $currentOrganisationId = $current->isSet()
            ? $current->id()
            : $request->session()->get('current_organisation_id');

        return [
            'user' => [
                'id' => $this->getAttribute('id'),
                'name' => $this->getAttribute('name'),
                'email' => $this->getAttribute('email'),
                'isSuperuser' => $this->isSuperuser,
            ],
            'organisations' => OrganisationResource::collection($this->organisations),
            'currentOrganisationId' => $currentOrganisationId,
        ];
    }
}
