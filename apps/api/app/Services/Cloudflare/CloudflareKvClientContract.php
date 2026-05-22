<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

use Tests\TenantTestCase;

/**
 * Minimal wrapper around the Cloudflare KV REST API. Two implementations:
 *
 * - {@see HttpCloudflareKvClient}  — production / dev, hits api.cloudflare.com
 * - {@see ArrayCloudflareKvClient} — bound in {@see TenantTestCase} so
 *   PHPUnit never makes real CF calls. Tests can introspect the captured
 *   payloads via getStore().
 */
interface CloudflareKvClientContract
{
    /**
     * @param  array<string, mixed>  $payload  arbitrary JSON-serialisable shape
     */
    public function putValue(string $key, array $payload): void;

    public function deleteValue(string $key): void;
}
