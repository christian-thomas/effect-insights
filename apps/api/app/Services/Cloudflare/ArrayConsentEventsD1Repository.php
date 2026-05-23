<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

use Tests\TenantTestCase;

/**
 * In-memory D1 fake bound in {@see TenantTestCase} so the archive
 * job can be tested without a real Cloudflare account.
 *
 * Tests seed rows via {@see seedRow()} and assert what remains via
 * {@see all()}.
 */
final class ArrayConsentEventsD1Repository implements ConsentEventsD1RepositoryContract
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    /**
     * @param  array<string, mixed>  $row
     */
    public function seedRow(array $row): void
    {
        $this->rows[] = $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->rows;
    }

    public function clear(): void
    {
        $this->rows = [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[\Override]
    public function fetchBefore(string $cutoffIso, int $limit): array
    {
        $filtered = array_filter(
            $this->rows,
            static fn (array $row): bool => isset($row['created_at']) && (string) $row['created_at'] < $cutoffIso,
        );

        usort(
            $filtered,
            static fn (array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']),
        );

        return array_slice($filtered, 0, $limit);
    }

    /**
     * @param  array<int, string>  $ids
     */
    #[\Override]
    public function deleteByIds(array $ids): void
    {
        $idSet = array_flip($ids);
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => ! isset($idSet[(string) ($row['id'] ?? '')]),
        ));
    }
}
