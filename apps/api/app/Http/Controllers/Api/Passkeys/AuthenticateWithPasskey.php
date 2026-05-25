<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Passkeys;

use App\Actions\Passkeys\AuthenticateWithPasskey as AuthenticateWithPasskeyAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Step 2 of WebAuthn authentication — receives the
 * AuthenticatorAssertionResponse from `navigator.credentials.get()`
 * and delegates the WebAuthn ceremony + session bind to
 * {@see AuthenticateWithPasskeyAction}.
 */
class AuthenticateWithPasskey extends Controller
{
    public function __invoke(
        Request $request,
        AuthenticateWithPasskeyAction $authenticate,
    ): JsonResponse {
        $user = $authenticate($request);

        return UserResource::make($user->load('organisations'))->response();
    }
}
