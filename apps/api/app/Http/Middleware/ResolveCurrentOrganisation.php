<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organisation;
use App\Models\User;
use App\Support\CurrentOrganisation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resolves the active organisation for the current request from the session.
 * Runs INSIDE the `auth:sanctum` group, after authentication.
 *
 * Flow:
 *   1. Read session('current_organisation_id').
 *   2. If null and the user has organisations, default to the first one and
 *      persist it back to the session (saves a round-trip on first login).
 *   3. Validate access:
 *        - Superuser → bind without membership check
 *        - Regular user → must be a member of the org
 *      On validation failure, clear the session key and 403 — frontend can
 *      recover by re-fetching /api/me.
 *   4. Set the injected CurrentOrganisation service.
 */
class ResolveCurrentOrganisation
{
    public function __construct(
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $sessionKey = 'current_organisation_id';
        $organisationId = $request->session()->get($sessionKey);

        if ($organisationId === null) {
            $organisationId = $user->organisations()->value('organisations.id');

            if ($organisationId === null) {
                return $next($request);
            }

            $request->session()->put($sessionKey, $organisationId);
        }

        $organisation = Organisation::query()->whereKey($organisationId)->first();

        if ($organisation === null) {
            $request->session()->forget($sessionKey);

            throw new HttpException(Response::HTTP_FORBIDDEN, 'Selected organisation no longer exists');
        }

        if (! $user->isSuperuser) {
            $isMember = $user->organisations()->whereKey($organisationId)->exists();

            if (! $isMember) {
                $request->session()->forget($sessionKey);

                throw new HttpException(Response::HTTP_FORBIDDEN, 'You are not a member of the selected organisation');
            }
        }

        $this->currentOrganisation->set($organisation);

        return $next($request);
    }
}
