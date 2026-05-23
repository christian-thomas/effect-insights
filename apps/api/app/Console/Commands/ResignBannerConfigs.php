<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BannerConfig;
use App\Services\BannerConfigService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Re-signs and re-publishes every currently-published banner config to
 * Cloudflare KV. Use it for:
 *
 *   - First deploy of the signing feature (existing KV records have no
 *     signature → the config worker would refuse to serve them).
 *   - Rolling BANNER_CONFIG_SIGNING_KEY — re-sign with the new value
 *     after both Laravel and the worker have been updated.
 *   - Recovering from suspected KV drift.
 *
 * Idempotent: calls BannerConfigService::pushToEdge() which is a plain
 * KV put.
 */
#[Signature('banner-configs:re-sign')]
#[Description('Re-sign + re-publish every published banner config to Cloudflare KV.')]
class ResignBannerConfigs extends Command
{
    public function handle(BannerConfigService $service): int
    {
        $count = 0;
        $failed = 0;

        $configs = BannerConfig::query()
            ->withoutGlobalScope('organisation')
            ->where('is_published', true)
            ->lazy();

        foreach ($configs as $config) {
            try {
                $service->pushToEdge($config);
                $count++;
            } catch (Throwable $exception) {
                $failed++;
                $this->error(sprintf(
                    'Failed to re-sign %s: %s',
                    $config->getKey(),
                    $exception->getMessage(),
                ));
            }
        }

        $this->info(sprintf('Re-signed %d banner config(s). %d failed.', $count, $failed));

        return $failed > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
