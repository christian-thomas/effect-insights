<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Cloudflare\CloudflareKvClientContract;
use App\Services\Cloudflare\HttpCloudflareKvClient;
use App\Support\CurrentOrganisation;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentOrganisation::class);

        $this->app->singleton(CloudflareKvClientContract::class, function ($app): HttpCloudflareKvClient {
            $config = $app['config']->get('services.cloudflare');

            return new HttpCloudflareKvClient(
                http: $app->make(HttpFactory::class),
                accountId: (string) ($config['account_id'] ?? ''),
                apiToken: (string) ($config['api_token'] ?? ''),
                namespaceId: (string) ($config['kv_namespace_id'] ?? ''),
                apiBase: (string) ($config['api_base'] ?? 'https://api.cloudflare.com/client/v4'),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
