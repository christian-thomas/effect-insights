<?php

declare(strict_types=1);

namespace App\Services\ConsentEvents;

use App\Models\ConsentEvent;
use App\Models\Organisation;
use Carbon\CarbonImmutable;

/**
 * Hard-deletes consent events that have aged out of their tenant's
 * configured retention window.
 *
 * Per-organisation retention lives on `organisations.consent_retention_days`:
 *   - NULL → indefinite retention (default, what the platform did before
 *     this feature)
 *   - N    → delete every consent_event whose `occurred_at` is older than
 *     N days
 *
 * Triggered by the consent-events:purge artisan command on the daily
 * scheduler. Returns the per-org delete counts so the caller can log
 * + alert (currently just logged via the command output).
 *
 * Stays within the MySQL archive. The D1 hot store is already truncated
 * daily by ArchiveConsentEventsService — it never holds rows older than
 * the previous archive run, which is well inside any sensible retention
 * window.
 */
final readonly class PurgeExpiredConsentEventsService
{
    /**
     * @return array<int, array{organisationId: string, deleted: int, threshold: string}>
     */
    public function purge(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        $results = [];

        $organisations = Organisation::query()
            ->whereNotNull('consent_retention_days')
            ->where('consent_retention_days', '>', 0)
            ->get();

        foreach ($organisations as $organisation) {
            $retention = (int) $organisation->getAttribute('consent_retention_days');
            $threshold = $now->subDays($retention);

            $deleted = ConsentEvent::query()
                ->withoutGlobalScope('organisation')
                ->where('organisation_id', $organisation->getKey())
                ->where('occurred_at', '<', $threshold)
                ->delete();

            $results[] = [
                'organisationId' => (string) $organisation->getKey(),
                'deleted' => $deleted,
                'threshold' => $threshold->toIso8601String(),
            ];
        }

        return $results;
    }
}
