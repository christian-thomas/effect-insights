<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Passkeys;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;

/**
 * Step 1 of WebAuthn authentication. Returns the
 * PublicKeyCredentialRequestOptions JSON that the SPA hands to
 * `navigator.credentials.get()`. The action also stashes the same
 * payload in the session under `passkey-authentication-options` so
 * the subsequent verify step can re-read it (replay protection).
 *
 * Unauthenticated by design — the visitor hasn't logged in yet.
 * Anyone can request a challenge; the server only commits the
 * session to a user after the assertion verifies in step 2.
 */
class GenerateAuthenticationOptions extends Controller
{
    public function __invoke(GeneratePasskeyAuthenticationOptionsAction $generate): JsonResponse
    {
        $optionsJson = $generate->execute();

        return response()->json(json_decode($optionsJson, true));
    }
}
