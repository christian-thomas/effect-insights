<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Passkeys;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;

/**
 * Step 1 of WebAuthn registration: server produces the
 * PublicKeyCredentialCreationOptions JSON. The admin SPA hands this
 * straight to `navigator.credentials.create()` so the browser can
 * prompt the user (TouchID, Windows Hello, YubiKey, etc).
 *
 * The options are stashed in the session under the same key the
 * spatie package's Livewire/Blade flow uses — that way the StorePasskey
 * controller can call StorePasskeyAction with the original options
 * to verify the browser's response wasn't tampered with.
 */
class GenerateRegisterOptions extends Controller
{
    public function __invoke(
        Request $request,
        GeneratePasskeyRegisterOptionsAction $generate,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $optionsJson = $generate->execute($user);

        // Sibling-key to the authentication-options stash used by
        // GenerateAuthenticationOptions; gives the StorePasskey
        // controller a deterministic place to pick the original
        // server challenge up from.
        $request->session()->put('passkey-register-options', $optionsJson);

        // json_decode → response()->json so the response body is the
        // raw WebAuthn structure (no `{ data: { ... } }` envelope —
        // the WebAuthn JS API consumes the structure directly).
        return response()->json(json_decode($optionsJson, true));
    }
}
