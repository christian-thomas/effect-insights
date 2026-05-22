<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\Login;
use App\Http\Controllers\Api\Auth\Logout;
use App\Http\Controllers\Api\Auth\Me;
use App\Http\Controllers\Api\BannerConfigs\PublishBannerConfig;
use App\Http\Controllers\Api\BannerConfigs\ShowBannerConfig;
use App\Http\Controllers\Api\BannerConfigs\UpdateBannerDraft;
use App\Http\Controllers\Api\Dashboard\ShowDashboard;
use App\Http\Controllers\Api\Domains\CreateDomain;
use App\Http\Controllers\Api\Domains\DeleteDomain;
use App\Http\Controllers\Api\Domains\ListDomains;
use App\Http\Controllers\Api\Domains\ShowDomain;
use App\Http\Controllers\Api\Domains\UpdateDomain;
use App\Http\Controllers\Api\Organisations\CreateOrganisation;
use App\Http\Controllers\Api\Organisations\DeleteOrganisation;
use App\Http\Controllers\Api\Organisations\ListOrganisations;
use App\Http\Controllers\Api\Organisations\ShowOrganisation;
use App\Http\Controllers\Api\Organisations\SwitchOrganisation;
use App\Http\Controllers\Api\Organisations\UpdateOrganisation;
use Illuminate\Support\Facades\Route;

Route::post('/login', Login::class);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', Me::class);
    Route::post('/logout', Logout::class);

    Route::post('/organisations/switch', SwitchOrganisation::class);

    // CRUD on organisations is superuser-only — managing white-label tenants.
    Route::middleware('superuser')->group(function (): void {
        Route::get('/organisations', ListOrganisations::class);
        Route::post('/organisations', CreateOrganisation::class);
        Route::get('/organisations/{organisation}', ShowOrganisation::class);
        Route::patch('/organisations/{organisation}', UpdateOrganisation::class);
        Route::delete('/organisations/{organisation}', DeleteOrganisation::class);
    });

    // Anything tenant-scoped goes through the org.current middleware which
    // binds the CurrentOrganisation service from the session.
    Route::middleware('org.current')->group(function (): void {
        Route::get('/dashboard', ShowDashboard::class);

        Route::get('/domains', ListDomains::class);
        Route::post('/domains', CreateDomain::class);
        Route::get('/domains/{domain}', ShowDomain::class);
        Route::patch('/domains/{domain}', UpdateDomain::class);
        Route::delete('/domains/{domain}', DeleteDomain::class);

        Route::get('/domains/{domain}/banner-config', ShowBannerConfig::class);
        Route::put('/domains/{domain}/banner-config/draft', UpdateBannerDraft::class);
        Route::post('/domains/{domain}/banner-config/publish', PublishBannerConfig::class);
    });
});
