<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\OrganisationMembers;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class RemoveMember extends Controller
{
    public function __invoke(Request $request, Organisation $organisation, User $user): Response
    {
        /** @var User $actingUser */
        $actingUser = $request->user();

        // A superuser shouldn't be able to detach themselves while acting
        // as one — that's a footgun that locks them out of the very tenant
        // they're operating against.
        abort_if(
            $user->getKey() === $actingUser->getKey(),
            HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
            "You can't remove yourself from an organisation.",
        );

        $organisation->users()->detach($user->getKey());

        return response()->noContent(HttpResponse::HTTP_NO_CONTENT);
    }
}
