<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Passkeys;

use App\Actions\Passkeys\RegisterPasskey;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePasskeyRequest;
use App\Http\Resources\PasskeyResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 2 of WebAuthn registration. Delegates the ceremony to
 * {@see RegisterPasskey}.
 *
 * Request body:
 *   - passkey:  JSON string from the browser
 *   - name:     human-readable label (e.g. "My MacBook")
 */
class StorePasskey extends Controller
{
    public function __invoke(StorePasskeyRequest $request, RegisterPasskey $register): JsonResponse
    {
        /** @var array{passkey: string, name: string} $validated */
        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();

        return PasskeyResource::make($register($request, $user, $validated))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
