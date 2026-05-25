<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Invitations;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserInvitationResource;
use App\Models\Organisation;
use App\Models\UserInvitation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListInvitations extends Controller
{
    public function __invoke(Organisation $organisation): AnonymousResourceCollection
    {
        $invitations = UserInvitation::query()
            ->where('organisation_id', $organisation->getKey())
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with('invitedBy')
            ->latest()
            ->get();

        return UserInvitationResource::collection($invitations);
    }
}
