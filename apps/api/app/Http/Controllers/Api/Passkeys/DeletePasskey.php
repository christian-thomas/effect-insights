<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Passkeys;

use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Revoke a passkey. Scoped to the current user — operators can only
 * remove credentials registered against their own account; trying to
 * remove someone else's returns 404 rather than 403 to avoid leaking
 * existence.
 */
class DeletePasskey extends Controller
{
    public function __invoke(Request $request, Passkey $passkey): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ((string) $passkey->getAttribute('authenticatable_id') !== (string) $user->getKey()) {
            throw new NotFoundHttpException;
        }

        $passkey->delete();

        return response()->noContent();
    }
}
