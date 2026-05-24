<?php

declare(strict_types=1);

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Route;

Route::post('/login', Api\Auth\Login::class);

// Internal mirror endpoint the edge consent worker posts to. No Sanctum
// session — the worker is server-to-server. Guarded by a shared-secret
// header (CONSENT_INGEST_SECRET).
Route::middleware('consent.ingest')->group(function (): void {
    Route::post('/internal/consent-events', Api\Internal\IngestConsentEvent::class);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', Api\Auth\Me::class);
    Route::post('/logout', Api\Auth\Logout::class);

    Route::post('/organisations/switch', Api\Organisations\SwitchOrganisation::class);

    // CRUD on organisations is superuser-only — managing white-label tenants.
    Route::middleware('superuser')->group(function (): void {
        Route::get('/organisations', Api\Organisations\ListOrganisations::class);
        Route::post('/organisations', Api\Organisations\CreateOrganisation::class);
        Route::get('/organisations/{organisation}', Api\Organisations\ShowOrganisation::class);
        Route::patch('/organisations/{organisation}', Api\Organisations\UpdateOrganisation::class);
        Route::delete('/organisations/{organisation}', Api\Organisations\DeleteOrganisation::class);

        // Cookie database review queue — global, not tenant-scoped.
        Route::get('/cookie-imports', Api\CookieImports\ListCookieImports::class);
        Route::post('/cookie-imports/{cookieImport}/promote', Api\CookieImports\PromoteCookieImport::class);
        Route::post('/cookie-imports/{cookieImport}/reject', Api\CookieImports\RejectCookieImport::class);
    });

    // The canonical cookie DB is global; anyone authed can read it.
    Route::get('/cookies', Api\Cookies\ListCookies::class);

    // Anything tenant-scoped goes through the org.current middleware which
    // binds the CurrentOrganisation service from the session.
    Route::middleware('org.current')->group(function (): void {
        Route::get('/dashboard', Api\Dashboard\ShowDashboard::class);

        Route::get('/domains', Api\Domains\ListDomains::class);
        Route::post('/domains', Api\Domains\CreateDomain::class);
        Route::get('/domains/{domain}', Api\Domains\ShowDomain::class);
        Route::patch('/domains/{domain}', Api\Domains\UpdateDomain::class);
        Route::delete('/domains/{domain}', Api\Domains\DeleteDomain::class);

        Route::get('/domains/{domain}/banner-config', Api\BannerConfigs\ShowBannerConfig::class);
        Route::get('/domains/{domain}/banner-config/diff', Api\BannerConfigs\ShowBannerConfigDiff::class);
        Route::put('/domains/{domain}/banner-config/draft', Api\BannerConfigs\UpdateBannerDraft::class);
        Route::post('/domains/{domain}/banner-config/publish', Api\BannerConfigs\PublishBannerConfig::class);
        Route::get('/domains/{domain}/banner-preview', Api\BannerPreview\ShowBannerPreview::class);

        Route::get('/consent-events', Api\ConsentEvents\ListConsentEvents::class);
        // Visitor-level (anonymous_id + domain_id) bundle must be declared
        // BEFORE the {event} binding below so /receipts/by-anonymous-id isn't
        // mis-resolved as an event UUID.
        Route::get('/consent-events/receipts/by-anonymous-id', Api\ConsentEvents\ExportVisitorReceipt::class);
        Route::get('/consent-events/{event}/receipt', Api\ConsentEvents\ExportEventReceipt::class);

        Route::get('/scans', Api\Scans\ListScans::class);
        Route::get('/scans/{scan}', Api\Scans\ShowScan::class);
        Route::post('/domains/{domain}/scans', Api\Scans\TriggerScan::class);

        // Per-organisation (and optionally per-domain) cookie categorisation
        // overrides. Resolution precedence is enforced server-side by
        // CookieCategoryResolver; these endpoints just CRUD the rows.
        Route::get('/cookie-overrides', Api\CookieOverrides\ListCookieOverrides::class);
        Route::post('/cookie-overrides', Api\CookieOverrides\CreateCookieOverride::class);
        Route::patch('/cookie-overrides/{cookieOverride}', Api\CookieOverrides\UpdateCookieOverride::class);
        Route::delete('/cookie-overrides/{cookieOverride}', Api\CookieOverrides\DeleteCookieOverride::class);
    });
});
