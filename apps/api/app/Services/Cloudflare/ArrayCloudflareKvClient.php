<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

/**
 * In-memory KV fake bound during PHPUnit so tests never hit the real
 * Cloudflare API. Use {@see getStore()} or {@see get()} to assert the
 * shape of writes.
 */
final class ArrayCloudflareKvClient implements CloudflareKvClientContract
{
    /** @var array<string, array<string, mixed>> */
    private array $store = [];

    public function putValue(string $key, array $payload): void
    {
        $this->store[$key] = $payload;
    }

    public function deleteValue(string $key): void
    {
        unset($this->store[$key]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        return $this->store[$key] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getStore(): array
    {
        return $this->store;
    }

    public function clear(): void
    {
        $this->store = [];
    }
}
