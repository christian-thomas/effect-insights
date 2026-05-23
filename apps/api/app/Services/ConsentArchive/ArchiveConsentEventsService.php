<?php

declare(strict_types=1);

namespace App\Services\ConsentArchive;

use App\Models\ConsentEvent;
use App\Models\Domain;
use App\Services\Cloudflare\ConsentEventsD1RepositoryContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Drains old rows from Cloudflare D1 into the canonical MySQL
 * `consent_events` table.
 *
 * Flow per batch:
 *   1. Read N rows from D1 with created_at < (now - retentionDays).
 *   2. Upsert each into MySQL (updateOrCreate keyed on the event UUID —
 *      so the edge worker's fire-and-forget mirror landing first is
 *      fine; we just no-op).
 *   3. Delete those IDs from D1.
 *
 * Wrapped in a transaction per batch so a partial upsert can't leave
 * D1 dropped + MySQL stale. The job repeats until D1 returns < batchSize,
 * meaning we drained everything older than the cutoff.
 */
final readonly class ArchiveConsentEventsService
{
    public const int DEFAULT_RETENTION_DAYS = 90;

    public const int DEFAULT_BATCH_SIZE = 500;

    public function __construct(
        private ConsentEventsD1RepositoryContract $repository,
    ) {}

    public function archive(
        int $retentionDays = self::DEFAULT_RETENTION_DAYS,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        ?Carbon $now = null,
    ): ArchiveResult {
        $cutoff = ($now ?? now())->subDays($retentionDays);
        $cutoffIso = $cutoff->toIso8601String();

        $upserted = 0;
        $deleted = 0;
        $skipped = 0;

        while (true) {
            $batch = $this->repository->fetchBefore($cutoffIso, $batchSize);
            if ($batch === []) {
                break;
            }

            [$batchUpserted, $batchSkipped, $idsToDelete] = $this->persistBatch($batch);

            $this->repository->deleteByIds($idsToDelete);

            $upserted += $batchUpserted;
            $skipped += $batchSkipped;
            $deleted += count($idsToDelete);

            // Last partial page — D1's exhausted everything older than cutoff.
            if (count($batch) < $batchSize) {
                break;
            }
        }

        return new ArchiveResult(
            upserted: $upserted,
            deleted: $deleted,
            skipped: $skipped,
            cutoff: $cutoffIso,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: int, 1: int, 2: array<int, string>}
     */
    private function persistBatch(array $rows): array
    {
        $upserted = 0;
        $skipped = 0;
        $idsToDelete = [];

        DB::transaction(function () use ($rows, &$upserted, &$skipped, &$idsToDelete): void {
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '') {
                    $skipped++;

                    continue;
                }

                // The edge worker has already written `domain_id` and
                // `organisation_id` (resolved when mirroring), but if the
                // mirror failed and we're recovering from D1, the row may
                // only carry `domain_public_key`. Resolve it.
                $organisationId = $this->resolveOrganisationId($row);
                if ($organisationId === null) {
                    $skipped++;

                    continue;
                }

                ConsentEvent::query()
                    ->withoutGlobalScope('organisation')
                    ->updateOrCreate(
                        ['id' => $id],
                        $this->mapToEloquent($row, $organisationId),
                    );

                $idsToDelete[] = $id;
                $upserted++;
            }
        });

        return [$upserted, $skipped, $idsToDelete];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveOrganisationId(array $row): ?string
    {
        if (isset($row['organisation_id'])) {
            return (string) $row['organisation_id'];
        }

        $domainId = (string) ($row['domain_id'] ?? '');
        if ($domainId !== '') {
            $domain = Domain::query()->withoutGlobalScope('organisation')->find($domainId);

            return $domain?->getAttribute('organisation_id');
        }

        $publicKey = (string) ($row['domain_public_key'] ?? '');
        if ($publicKey !== '') {
            $domain = Domain::query()
                ->withoutGlobalScope('organisation')
                ->where('public_key', $publicKey)
                ->first();

            return $domain?->getAttribute('organisation_id');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapToEloquent(array $row, string $organisationId): array
    {
        return [
            'domain_id' => (string) ($row['domain_id'] ?? ''),
            'organisation_id' => $organisationId,
            'banner_config_version' => (int) ($row['banner_config_version'] ?? 0),
            'anonymous_id' => (string) ($row['anonymous_id'] ?? ''),
            'categories' => $this->decodeJson($row['categories'] ?? []),
            'gcm_signals' => $this->decodeJson($row['gcm_signals'] ?? []),
            'country_code' => $row['country_code'] ?? null,
            'user_agent_hash' => (string) ($row['user_agent_hash'] ?? ''),
            'ip_hash' => (string) ($row['ip_hash'] ?? ''),
            'event_type' => (string) ($row['event_type'] ?? 'granted'),
            'occurred_at' => (string) ($row['occurred_at'] ?? ($row['created_at'] ?? now()->toIso8601String())),
            'banner_text_hash' => (string) ($row['banner_text_hash'] ?? ''),
        ];
    }

    /**
     * D1 stores JSON columns as strings on the wire; the array fake passes
     * them through as-is. Accept either.
     *
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($value, associative: true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
