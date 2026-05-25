<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Passkeys;

use App\Http\Controllers\Controller;
use App\Http\Resources\PasskeyResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Exceptions\InvalidPasskey;
use Spatie\LaravelPasskeys\Exceptions\InvalidPasskeyOptions;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 2 of WebAuthn registration. The browser (via
 * `navigator.credentials.create()`) produces a `PublicKeyCredential`
 * JSON describing the new credential; the SPA POSTs it here. The
 * action validates the response against the options we stashed in
 * the session at step 1 — replay protection — and persists.
 *
 * Request body:
 *   - passkey:  JSON string from the browser
 *   - name:     human-readable label (e.g. "My MacBook")
 */
class StorePasskey extends Controller
{
    public function __invoke(Request $request, StorePasskeyAction $store): JsonResponse
    {
        $validated = $request->validate([
            'passkey' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $passkeyOptionsJson = (string) $request->session()->pull('passkey-register-options', '');
        if ($passkeyOptionsJson === '') {
            return response()->json(
                ['message' => 'No registration options found in session — request a fresh challenge.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $passkey = $store->execute(
                authenticatable: $user,
                passkeyJson: $validated['passkey'],
                passkeyOptionsJson: $passkeyOptionsJson,
                hostName: (string) $request->getHost(),
                additionalProperties: ['name' => $validated['name']],
            );
        } catch (InvalidPasskey|InvalidPasskeyOptions $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return PasskeyResource::make($passkey)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
