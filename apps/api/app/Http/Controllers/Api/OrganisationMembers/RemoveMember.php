<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\OrganisationMembers;

use App\Actions\Organisations\RemoveMember as RemoveMemberAction;
use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class RemoveMember extends Controller
{
    public function __invoke(
        Request $request,
        Organisation $organisation,
        User $user,
        RemoveMemberAction $remove,
    ): Response {
        /** @var User $actingUser */
        $actingUser = $request->user();
        $remove($organisation, $user, $actingUser);

        return response()->noContent(HttpResponse::HTTP_NO_CONTENT);
    }
}
