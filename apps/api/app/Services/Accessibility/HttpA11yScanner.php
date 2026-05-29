<?php

declare(strict_types=1);

namespace App\Services\Accessibility;

use App\Models\Domain;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Posts the URL list to the dedicated Cloudflare scanner worker at
 * `/scan-a11y` (shared route with the cookie scanner, different
 * action). The worker handles the actual Browser Rendering binding,
 * injects axe-core after page.goto, and returns normalised findings.
 *
 * Reuses the same `SCANNER_WORKER_URL` + `SCANNER_WORKER_SECRET` env
 * vars as the cookie scanner — one worker, two routes.
 */
final readonly class HttpA11yScanner implements AccessibilityScannerClientContract
{
    public function __construct(
        private HttpFactory $http,
        private string $workerUrl,
        private string $secret,
        private int $timeoutSeconds = 120,
    ) {}

    /**
     * @param  array<int, string>  $urls
     */
    #[\Override]
    public function scan(
        Domain $domain,
        array $urls,
        string $organisationId,
        string $scanId,
    ): AccessibilityScannerResult {
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
            ->post(rtrim($this->workerUrl, '/').'/scan-a11y', [
                'urls' => array_values($urls),
                'domainFqdn' => (string) $domain->getAttribute('fqdn'),
                'organisationId' => $organisationId,
                'scanId' => $scanId,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Accessibility scanner returned %d: %s',
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

        return new AccessibilityScannerResult(
            urlsDiscovered: (int) ($body['urlsDiscovered'] ?? 0),
            urlsScanned: (int) ($body['urlsScanned'] ?? 0),
            findings: $this->mapFindings($body['findings'] ?? []),
            failedUrls: $failedUrls,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>|mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapFindings(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(static fn (array $row): array => [
            'rule_id' => (string) ($row['ruleId'] ?? $row['rule_id'] ?? ''),
            'impact' => (string) ($row['impact'] ?? 'minor'),
            'description' => (string) ($row['description'] ?? ''),
            'help_text' => (string) ($row['help'] ?? $row['help_text'] ?? ''),
            'help_url' => (string) ($row['helpUrl'] ?? $row['help_url'] ?? ''),
            'url' => (string) ($row['url'] ?? ''),
            'tags' => is_array($row['tags'] ?? null) ? array_values($row['tags']) : [],
            'nodes' => is_array($row['nodes'] ?? null) ? array_values($row['nodes']) : [],
            'raw' => $row,
        ], $rows));
    }
}
