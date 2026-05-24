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

// Public cookie-policy embed. Looked up by domain.public_key so embedders
// never need the internal UUID. Caching is moderate (1h fresh, 24h SWR) —
// a fresh scan needs ~an hour to surface to embedded iframes.
Route::get('/cookie-policy/{publicKey}.html', Api\CookiePolicies\ShowPublicCookiePolicy::class)
    ->where('publicKey', 'pk_[A-Za-z0-9_-]+');

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

        Route::get('/domains/{domain}/cookie-policy', Api\CookiePolicies\ShowCookiePolicy::class);

        Route::get('/domains/{domain}/banner-config', Api\BannerConfigs\ShowBannerConfig::class);
        Route::get('/domains/{domain}/banner-config/diff', Api\BannerConfigs\ShowBannerConfigDiff::class);
        Route::get('/domains/{domain}/banner-config/history', Api\BannerConfigs\ListBannerConfigHistory::class);
        Route::post('/domains/{domain}/banner-config/rollback', Api\BannerConfigs\RollbackBannerConfig::class);
        Route::put('/domains/{domain}/banner-config/draft', Api\BannerConfigs\UpdateBannerDraft::class);
        Route::post('/domains/{domain}/banner-config/publish', Api\BannerConfigs\PublishBannerConfig::class);
        Route::get('/domains/{domain}/banner-preview', Api\BannerPreview\ShowBannerPreview::class);

        Route::get('/consent-events', Api\ConsentEvents\ListConsentEvents::class);
        // CSV bulk export. Same filter surface as the list endpoint
        // (domain_id / anonymous_id / event_type / from / to). Streamed
        // via DB cursor — no pagination.
        Route::get('/consent-events.csv', Api\ConsentEvents\ExportConsentEventsCsv::class);
        // Visitor-level (anonymous_id + domain_id) bundle must be declared
        // BEFORE the {event} binding below so /receipts/by-anonymous-id isn't
        // mis-resolved as an event UUID.
        Route::get('/consent-events/receipts/by-anonymous-id', Api\ConsentEvents\ExportVisitorReceipt::class);
        Route::get('/consent-events/{event}/receipt', Api\ConsentEvents\ExportEventReceipt::class);

        Route::get('/scans', Api\Scans\ListScans::class);
        Route::get('/scans/{scan}', Api\Scans\ShowScan::class);
        Route::post('/domains/{domain}/scans', Api\Scans\TriggerScan::class);

        // Accessibility scans — sibling of the cookie scanner. Same
        // pagination + `?domain_id=` filter shape so the admin reads
        // the same way as /scans.
        Route::get('/accessibility-scans', Api\AccessibilityScans\ListAccessibilityScans::class);
        Route::get('/accessibility-scans/{accessibilityScan}', Api\AccessibilityScans\ShowAccessibilityScan::class);
        Route::post('/domains/{domain}/accessibility-scans', Api\AccessibilityScans\TriggerAccessibilityScan::class);
        // Per-domain time series of completed-scan violation counts.
        // ?days=N (7-365, default 90) for the window. One data point
        // per completed scan, oldest-first.
        Route::get('/domains/{domain}/accessibility-trend', Api\AccessibilityScans\ShowAccessibilityTrend::class);

        // Per-org / per-domain mute list for axe-core rule IDs. Sibling
        // of cookie-overrides; suppresses a rule from alert thresholds
        // and dims it in the scan detail UI.
        Route::get('/accessibility-rule-overrides', Api\AccessibilityRuleOverrides\ListAccessibilityRuleOverrides::class);
        Route::post('/accessibility-rule-overrides', Api\AccessibilityRuleOverrides\CreateAccessibilityRuleOverride::class);
        Route::delete('/accessibility-rule-overrides/{accessibilityRuleOverride}', Api\AccessibilityRuleOverrides\DeleteAccessibilityRuleOverride::class);

        // Manual review queue: cookies the scanner saw but the resolver
        // couldn't categorise, grouped by name. The /cookies admin page
        // surfaces this as the "Review queue" tab.
        Route::get('/scan-findings/review', Api\ScanFindings\ListReviewQueue::class);

        // Per-organisation (and optionally per-domain) cookie categorisation
        // overrides. Resolution precedence is enforced server-side by
        // CookieCategoryResolver; these endpoints just CRUD the rows.
        Route::get('/cookie-overrides', Api\CookieOverrides\ListCookieOverrides::class);
        Route::post('/cookie-overrides', Api\CookieOverrides\CreateCookieOverride::class);
        Route::patch('/cookie-overrides/{cookieOverride}', Api\CookieOverrides\UpdateCookieOverride::class);
        Route::delete('/cookie-overrides/{cookieOverride}', Api\CookieOverrides\DeleteCookieOverride::class);
    });
});
