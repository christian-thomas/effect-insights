<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Passkeys;

use App\Http\Controllers\Controller;
use App\Http\Resources\PasskeyResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * List the current user's registered passkeys. Powers the admin
 * settings page so operators can audit + revoke credentials.
 */
class ListPasskeys extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return PasskeyResource::collection(
            $user->passkeys()->latest('created_at')->get(),
        );
    }
}
