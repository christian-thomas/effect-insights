<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Talks to Cloudflare D1's REST API. Endpoint shape:
 *
 *   POST /accounts/{account_id}/d1/database/{database_id}/query
 *   { "sql": "SELECT ...", "params": [...] }
 *
 * Returns the JSON `result[0].results` array as our rows. Throws on any
 * non-2xx so the archive job records the failure and bails out.
 */
final readonly class HttpConsentEventsD1Repository implements ConsentEventsD1RepositoryContract
{
    public function __construct(
        private HttpFactory $http,
        private string $accountId,
        private string $databaseId,
        private string $apiToken,
        private string $apiBase = 'https://api.cloudflare.com/client/v4',
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    #[\Override]
    public function fetchBefore(string $cutoffIso, int $limit): array
    {
        $this->assertConfigured();

        $response = $this->postQuery(
            'SELECT * FROM consent_events WHERE created_at < ?1 ORDER BY created_at ASC LIMIT ?2',
            [$cutoffIso, $limit],
        );

        /** @var array<string, mixed> $body */
        $body = $response;
        /** @var array<int, array<string, mixed>> $result */
        $result = $body['result'] ?? [];
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $result[0]['results'] ?? [];

        return $rows;
    }

    /**
     * @param  array<int, string>  $ids
     */
    #[\Override]
    public function deleteByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->assertConfigured();

        $placeholders = implode(',', array_map(static fn (int $index): string => '?'.($index + 1), array_keys($ids)));
        $this->postQuery(
            sprintf('DELETE FROM consent_events WHERE id IN (%s)', $placeholders),
            array_values($ids),
        );
    }

    /**
     * @param  array<int, mixed>  $params
     * @return array<string, mixed>
     */
    private function postQuery(string $sql, array $params): array
    {
        $url = sprintf(
            '%s/accounts/%s/d1/database/%s/query',
            rtrim($this->apiBase, '/'),
            $this->accountId,
            $this->databaseId,
        );

        $response = $this->http
            ->withToken($this->apiToken)
            ->acceptJson()
            ->timeout(30)
            ->post($url, ['sql' => $sql, 'params' => $params]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'D1 query failed (%d): %s',
                $response->status(),
                $response->body(),
            ));
        }

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    private function assertConfigured(): void
    {
        if ($this->accountId === '' || $this->databaseId === '' || $this->apiToken === '') {
            throw new RuntimeException(
                'D1 client is not configured. Set CF_ACCOUNT_ID + CF_D1_DATABASE_ID + CF_API_TOKEN.',
            );
        }
    }
}
