<?php

declare(strict_types=1);

namespace App\Actions\Organisations;

use App\Exceptions\LastMemberCannotBeRemoved;
use App\Exceptions\LastSuperuserCannotBeRemoved;
use App\Models\Organisation;
use App\Models\User;

/**
 * Removes a user from an organisation. Same-user removal is allowed
 * (operators can leave an org they no longer want admin rights in),
 * but is guarded by two safety nets:
 *
 * - The org must have another member after the detach, otherwise the
 *   org's domains / scans / banner configs would lose all admins.
 * - The platform must have another superuser after the detach, otherwise
 *   recovery would require DB access.
 *
 * Either guard fires a typed exception that renders 422 with an
 * operator-facing message.
 */
final readonly class RemoveMember
{
    public function __invoke(Organisation $organisation, User $target, User $actingUser): void
    {
        if ($target->getKey() === $actingUser->getKey()) {
            if ($organisation->users()->count() <= 1) {
                throw new LastMemberCannotBeRemoved;
            }

            if (
                $actingUser->hasRole(User::ROLE_SUPERUSER)
                && User::role(User::ROLE_SUPERUSER)->count() <= 1
            ) {
                throw new LastSuperuserCannotBeRemoved;
            }
        }

        $organisation->users()->detach($target->getKey());
    }
}
