<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final readonly class HttpCloudflareKvClient implements CloudflareKvClientContract
{
    public function __construct(
        private HttpFactory $http,
        private string $accountId,
        private string $apiToken,
        private string $namespaceId,
        private string $apiBase,
    ) {}

    public function putValue(string $key, array $payload): void
    {
        $response = $this->http
            ->withToken($this->apiToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->put($this->valueUrl($key), $payload);

        if ($response->status() !== Response::HTTP_OK) {
            throw new RuntimeException(sprintf(
                'Cloudflare KV PUT failed for key "%s": %d %s',
                $key,
                $response->status(),
                $response->body(),
            ));
        }
    }

    public function deleteValue(string $key): void
    {
        $response = $this->http
            ->withToken($this->apiToken)
            ->delete($this->valueUrl($key));

        if ($response->status() !== Response::HTTP_OK
            && $response->status() !== Response::HTTP_NOT_FOUND) {
            throw new RuntimeException(sprintf(
                'Cloudflare KV DELETE failed for key "%s": %d %s',
                $key,
                $response->status(),
                $response->body(),
            ));
        }
    }

    private function valueUrl(string $key): string
    {
        return sprintf(
            '%s/accounts/%s/storage/kv/namespaces/%s/values/%s',
            rtrim($this->apiBase, '/'),
            $this->accountId,
            $this->namespaceId,
            rawurlencode($key),
        );
    }
}
