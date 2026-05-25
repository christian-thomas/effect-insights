<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Invitations;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\UserInvitation;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class RevokeInvitation extends Controller
{
    public function __invoke(Organisation $organisation, UserInvitation $invitation): Response
    {
        abort_unless(
            $invitation->getAttribute('organisation_id') === $organisation->getKey(),
            HttpResponse::HTTP_NOT_FOUND,
        );

        $invitation->delete();

        return response()->noContent(HttpResponse::HTTP_NO_CONTENT);
    }
}
