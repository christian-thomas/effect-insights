<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Cloudflare\CloudflareKvClientContract;
use App\Services\Cloudflare\HttpCloudflareKvClient;
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

        // Real CF Browser Rendering client. Tests rebind this to
        // ArrayScannerClient in TenantTestCase::setUp.
        $this->app->singleton(ScannerClientContract::class, function (Application $app): HttpCfBrowserRenderingScanner {
            /** @var array<string, mixed> $config */
            $config = config('services.cloudflare', []);

            return new HttpCfBrowserRenderingScanner(
                http: $app->make(HttpFactory::class),
                accountId: (string) ($config['account_id'] ?? ''),
                apiToken: (string) ($config['api_token'] ?? ''),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
