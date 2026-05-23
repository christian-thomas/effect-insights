<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Organisation;
use RuntimeException;

/**
 * Per-request holder for the active organisation. Populated by the
 * ResolveCurrentOrganisation middleware after auth, consumed by the
 * BelongsToOrganisation global scope.
 *
 * Registered as a singleton in AppServiceProvider. Singleton lifetime is
 * effectively per-request because Laravel's container is rebuilt per request.
 */
final class CurrentOrganisation
{
    private ?Organisation $organisation = null;

    public function set(Organisation $organisation): void
    {
        $this->organisation = $organisation;
    }

    public function clear(): void
    {
        $this->organisation = null;
    }

    public function get(): ?Organisation
    {
        return $this->organisation;
    }

    public function getOrFail(): Organisation
    {
        if (! $this->organisation instanceof Organisation) {
            throw new RuntimeException('No current organisation bound for this request');
        }

        return $this->organisation;
    }

    public function isSet(): bool
    {
        return $this->organisation instanceof Organisation;
    }

    public function id(): ?string
    {
        return $this->organisation?->getKey();
    }
}
