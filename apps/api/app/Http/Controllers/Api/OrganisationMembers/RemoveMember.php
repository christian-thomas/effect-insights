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

        if ($user->getKey() === $actingUser->getKey()) {
            // Removing the last member would leave the org with domains /
            // scans / banner configs that nobody can administer. Block.
            $memberCount = $organisation->users()->count();
            abort_if(
                $memberCount <= 1,
                HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
                "You're the last member of this organisation. Add another member before removing yourself.",
            );

            // Self-removal as the only platform superuser is allowed
            // (superuser membership and org membership are orthogonal — they
            // can still switch into any org via the global /organisations
            // page), but a fat-finger here is irreversible without DB
            // access, so we block it as a safety net.
            if ($actingUser->hasRole(User::ROLE_SUPERUSER)) {
                $superuserCount = User::role(User::ROLE_SUPERUSER)->count();
                abort_if(
                    $superuserCount <= 1,
                    HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
                    "You're the last superuser. Grant another user the superuser role before removing yourself.",
                );
            }
        }

        $organisation->users()->detach($user->getKey());

        return response()->noContent(HttpResponse::HTTP_NO_CONTENT);
    }
}
