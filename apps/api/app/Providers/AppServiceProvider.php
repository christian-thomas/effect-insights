<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\CurrentOrganisation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentOrganisation::class);
    }

    public function boot(): void
    {
        //
    }
}
