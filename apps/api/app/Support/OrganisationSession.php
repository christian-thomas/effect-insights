<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Owns the `current_organisation_id` session key. Three sites bind a
 * default organisation context on sign-in (password Login, passkey
 * AuthenticateWithPasskey) or on explicit switch (SwitchOrganisation);
 * before this helper they each rolled the same three lines inline.
 *
 * The active organisation is the user's first membership row — there's
 * no notion of a "primary" org today, so first-by-pivot-order is the
 * deterministic default. {@see ResolveCurrentOrganisation} reads this
 * key on every authenticated request and binds the matching model into
 * the {@see CurrentOrganisation} container.
 */
final class OrganisationSession
{
    public const string KEY = 'current_organisation_id';

    /**
     * Seed the session with the user's first organisation membership, if
     * any. No-op when the user has zero memberships (e.g. a freshly
     * created superuser before they've been attached to anything).
     */
    public static function bindDefault(Request $request, User $user): void
    {
        $defaultOrganisationId = $user->organisations()->value('organisations.id');

        if ($defaultOrganisationId === null) {
            return;
        }

        $request->session()->put(self::KEY, $defaultOrganisationId);
    }

    /**
     * Bind an explicit organisation id (used by SwitchOrganisation when the
     * operator picks a different tenant from the dropdown).
     */
    public static function bind(Request $request, string $organisationId): void
    {
        $request->session()->put(self::KEY, $organisationId);
    }

    /**
     * Drop the bound organisation — used by sign-out flows so the next
     * authenticated session starts clean.
     */
    public static function forget(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }
}
