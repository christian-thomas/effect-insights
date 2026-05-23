<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    #[\Override]
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Who can hit /horizon in non-local environments. The dashboard
     * exposes queue internals + failed-job payloads, so it's superuser-only.
     */
    #[\Override]
    protected function gate(): void
    {
        Gate::define('viewHorizon', static fn (?User $user): bool => $user instanceof User && $user->isSuperuser);
    }
}
