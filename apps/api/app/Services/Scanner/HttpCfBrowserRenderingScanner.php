<?php

declare(strict_types=1);

namespace App\Services\Scanner;

use App\Models\Domain;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Posts the scan request to our dedicated Cloudflare scanner worker
 * (apps/edge/src/scanner-worker.ts). The worker holds the actual Browser
 * Rendering binding — it crawls the URLs with @cloudflare/puppeteer,
 * captures cookies / scripts / iframes / network requests, and returns
 * a normalised JSON payload.
 *
 * This client just wraps the HTTP call: it authenticates with a shared
 * secret in `X-Effect-Scanner-Secret`, posts the URL list, and parses
 * the JSON response into a {@see ScannerResult}.
 */
final readonly class HttpCfBrowserRenderingScanner implements ScannerClientContract
{
    public function __construct(
        private HttpFactory $http,
        private string $workerUrl,
        private string $secret,
        private int $timeoutSeconds = 60,
    ) {}

    /**
     * @param  array<int, string>  $urls
     */
    #[\Override]
    public function scan(Domain $domain, array $urls, string $organisationId, string $scanId): ScannerResult
    {
        if ($this->workerUrl === '' || $this->secret === '') {
            throw new RuntimeException(
                'Scanner worker is not configured. Set SCANNER_WORKER_URL + SCANNER_WORKER_SECRET '.
                'in .env and `wrangler secret put SCANNER_SECRET` on the worker.',
            );
        }

        $response = $this->http
            ->withHeaders([
                'X-Effect-Scanner-Secret' => $this->secret,
                'Accept' => 'application/json',
            ])
            ->timeout($this->timeoutSeconds)
            ->post(rtrim($this->workerUrl, '/').'/scan', [
                'urls' => array_values($urls),
                'domainFqdn' => (string) $domain->getAttribute('fqdn'),
                'organisationId' => $organisationId,
                'scanId' => $scanId,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Scanner worker returned %d: %s',
                $response->status(),
                $response->body(),
            ));
        }

        /** @var array<string, mixed> $body */
        $body = $response->json();

        /** @var list<string> $failedUrls */
        $failedUrls = is_array($body['failedUrls'] ?? null)
            ? array_values(array_map('strval', $body['failedUrls']))
            : [];

        return new ScannerResult(
            urlsDiscovered: (int) ($body['urlsDiscovered'] ?? 0),
            urlsScanned: (int) ($body['urlsScanned'] ?? 0),
            cookies: $this->mapCookies($body['cookies'] ?? []),
            scripts: $this->mapAssets($body['scripts'] ?? []),
            iframes: $this->mapAssets($body['iframes'] ?? []),
            requests: $this->mapAssets($body['requests'] ?? []),
            failedUrls: $failedUrls,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>|mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapCookies(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(static fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'domain' => isset($row['domain']) ? (string) $row['domain'] : null,
            'value_pattern' => isset($row['valuePattern']) ? (string) $row['valuePattern'] : null,
            'party' => (string) ($row['party'] ?? 'third'),
            'first_seen_url' => isset($row['firstSeenUrl']) ? (string) $row['firstSeenUrl'] : null,
            'raw' => $row,
        ], $rows));
    }

    /**
     * @param  array<int, array<string, mixed>>|mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapAssets(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(static fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'party' => (string) ($row['party'] ?? 'third'),
            'first_seen_url' => isset($row['firstSeenUrl']) ? (string) $row['firstSeenUrl'] : null,
            'raw' => $row,
        ], $rows));
    }
}
