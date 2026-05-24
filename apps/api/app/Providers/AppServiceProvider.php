<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Accessibility\AccessibilityScannerClientContract;
use App\Services\Accessibility\HttpA11yScanner;
use App\Services\BannerConfig\BannerConfigSigner;
use App\Services\Cloudflare\CloudflareKvClientContract;
use App\Services\Cloudflare\ConsentEventsD1RepositoryContract;
use App\Services\Cloudflare\HttpCloudflareKvClient;
use App\Services\Cloudflare\HttpConsentEventsD1Repository;
use App\Services\ConsentEvents\ConsentReceiptSigner;
use App\Services\Scanner\HttpCfBrowserRenderingScanner;
use App\Services\Scanner\ScannerClientContract;
use App\Support\CurrentOrganisation;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(CurrentOrganisation::class);

        $this->app->singleton(CloudflareKvClientContract::class, function (Application $app): HttpCloudflareKvClient {
            /** @var array<string, mixed> $config */
            $config = config('services.cloudflare', []);

            return new HttpCloudflareKvClient(
                http: $app->make(HttpFactory::class),
                accountId: (string) ($config['account_id'] ?? ''),
                apiToken: (string) ($config['api_token'] ?? ''),
                namespaceId: (string) ($config['kv_namespace_id'] ?? ''),
                apiBase: (string) ($config['api_base'] ?? 'https://api.cloudflare.com/client/v4'),
            );
        });

        $this->app->singleton(BannerConfigSigner::class, function (): BannerConfigSigner {
            /** @var array<string, mixed> $config */
            $config = config('services.banner_config', []);

            return new BannerConfigSigner(
                key: (string) ($config['signing_key'] ?? ''),
            );
        });

        $this->app->singleton(ConsentReceiptSigner::class, function (): ConsentReceiptSigner {
            /** @var array<string, mixed> $config */
            $config = config('services.consent_receipt', []);

            return new ConsentReceiptSigner(
                key: (string) ($config['signing_key'] ?? ''),
            );
        });

        // Real D1 client for the consent-events archive job. Tests rebind
        // this to ArrayConsentEventsD1Repository in TenantTestCase.
        $this->app->singleton(ConsentEventsD1RepositoryContract::class, function (Application $app): HttpConsentEventsD1Repository {
            /** @var array<string, mixed> $config */
            $config = config('services.cloudflare', []);

            return new HttpConsentEventsD1Repository(
                http: $app->make(HttpFactory::class),
                accountId: (string) ($config['account_id'] ?? ''),
                databaseId: (string) ($config['d1_database_id'] ?? ''),
                apiToken: (string) ($config['api_token'] ?? ''),
                apiBase: (string) ($config['api_base'] ?? 'https://api.cloudflare.com/client/v4'),
            );
        });

        // Real CF Browser Rendering client. Tests rebind this to
        // ArrayScannerClient in TenantTestCase::setUp.
        $this->app->singleton(ScannerClientContract::class, function (Application $app): HttpCfBrowserRenderingScanner {
            /** @var array<string, mixed> $config */
            $config = config('services.scanner', []);

            return new HttpCfBrowserRenderingScanner(
                http: $app->make(HttpFactory::class),
                workerUrl: (string) ($config['worker_url'] ?? ''),
                secret: (string) ($config['secret'] ?? ''),
                timeoutSeconds: (int) ($config['timeout_seconds'] ?? 60),
            );
        });

        // Accessibility (axe-core) scanner. Reuses the cookie scanner's
        // SCANNER_WORKER_URL + SCANNER_WORKER_SECRET — one worker, two
        // routes (/scan vs /scan-a11y). Tests rebind to ArrayA11yScanner.
        $this->app->singleton(AccessibilityScannerClientContract::class, function (Application $app): HttpA11yScanner {
            /** @var array<string, mixed> $config */
            $config = config('services.scanner', []);

            return new HttpA11yScanner(
                http: $app->make(HttpFactory::class),
                workerUrl: (string) ($config['worker_url'] ?? ''),
                secret: (string) ($config['secret'] ?? ''),
                // axe runs 2-5s per page so we give a11y scans a
                // longer HTTP budget than cookie scans (default 60s).
                timeoutSeconds: (int) ($config['a11y_timeout_seconds'] ?? 120),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
