<?php

declare(strict_types=1);

namespace App\Services\Accessibility;

use App\Enums\A11yImpact;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanAccessibilityUrlsJob;
use App\Models\AccessibilityFinding;
use App\Models\AccessibilityScan;
use App\Models\Domain;
use App\Models\User;
use App\Services\Notifications\AccessibilityAlertService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates a single accessibility scan. Mirrors the dispatch /
 * scanUrlChunk / finaliseBatch shape of {@see \App\Services\Scanner\ScanRunner}
 * so the two scanners look the same to anyone debugging either pipeline.
 *
 * Four entry points:
 *
 *   - {@see self::dispatch()}        Create an AccessibilityScan with
 *                                    `urls_discovered` pre-set, flip
 *                                    to `running`, and dispatch a
 *                                    {@see Bus::batch()} of
 *                                    {@see ScanAccessibilityUrlsJob}s —
 *                                    one per chunk of
 *                                    `services.scanner.url_chunk_size`
 *                                    URLs — so the operator sees
 *                                    `urls_scanned` tick up live.
 *   - {@see self::scanUrlChunk()}    Per-chunk work, called from
 *                                    {@see ScanAccessibilityUrlsJob}.
 *                                    Persists findings and bumps
 *                                    `urls_scanned`.
 *   - {@see self::run()}             Synchronous create + execute path
 *                                    — used by tests and the
 *                                    `accessibility:scan-domain`
 *                                    artisan command.
 *   - {@see self::execute()}         Synchronous one-shot: flip
 *                                    running, scan all URLs in a
 *                                    single worker call, persist
 *                                    findings, flip completed/failed.
 *                                    Kept so PHPUnit feature tests
 *                                    don't have to fake job batches.
 *
 * After completion, the alert service decides whether to fire the
 * Slack notification based on the impact threshold.
 */
final readonly class AccessibilityScanRunner
{
    public function __construct(
        private AccessibilityScannerClientContract $scanner,
        private AccessibilityAlertService $alerts,
    ) {}

    /**
     * @param  array<int, string>  $urls
     */
    public function dispatch(
        Domain $domain,
        array $urls,
        ScanTrigger $trigger = ScanTrigger::Manual,
        ?User $triggeredBy = null,
    ): AccessibilityScan {
        $urlList = array_values($urls);
        $scan = $this->createScan($domain, $trigger, $triggeredBy, count($urlList));

        if ($urlList === []) {
            $this->finaliseBatch($scan->getKey());

            return $scan->refresh();
        }

        // Flip running up-front so the admin shows activity the moment
        // the user clicks. Per-chunk jobs will tick urls_scanned as
        // they finish.
        $scan->update([
            'status' => ScanStatus::Running,
            'started_at' => now(),
        ]);

        $scanId = $scan->getKey();
        $chunkSize = max(1, (int) config('services.scanner.url_chunk_size', 5));
        $chunks = array_chunk($urlList, $chunkSize);

        $jobs = array_map(
            static fn (array $chunk): ScanAccessibilityUrlsJob => new ScanAccessibilityUrlsJob($scanId, $chunk),
            $chunks,
        );

        Bus::batch($jobs)
            ->name(sprintf('a11y-scan:%s', $scanId))
            // Partial coverage is better than no coverage — axe results
            // for the pages that did succeed are still useful evidence.
            ->allowFailures()
            ->finally(static function () use ($scanId): void {
                app(AccessibilityScanRunner::class)->finaliseBatch($scanId);
            })
            ->onQueue('accessibility-scans')
            ->dispatch();

        return $scan;
    }

    /**
     * @param  array<int, string>  $urls
     */
    public function run(
        Domain $domain,
        array $urls,
        ScanTrigger $trigger = ScanTrigger::Manual,
        ?User $triggeredBy = null,
    ): AccessibilityScan {
        $scan = $this->createScan($domain, $trigger, $triggeredBy, count(array_values($urls)));

        return $this->execute($scan, $urls);
    }

    /**
     * @param  array<int, string>  $urls
     */
    public function execute(AccessibilityScan $scan, array $urls): AccessibilityScan
    {
        $scan->update([
            'status' => ScanStatus::Running,
            'started_at' => now(),
        ]);

        try {
            /** @var Domain $domain */
            $domain = $scan->domain()->withoutGlobalScope('organisation')->firstOrFail();

            $result = $this->scanner->scan(
                $domain,
                $urls,
                (string) $scan->getAttribute('organisation_id'),
                (string) $scan->getKey(),
            );

            $byImpact = $this->countByImpact($result->findings);

            // Tri-state same as finaliseBatch().
            $hasFailures = $result->failedUrls !== [];
            $scanned = $result->urlsScanned;
            $discovered = $result->urlsDiscovered;
            if ($scanned >= $discovered && ! $hasFailures) {
                $status = ScanStatus::Completed;
            } elseif ($scanned > 0) {
                $status = ScanStatus::Partial;
            } else {
                $status = ScanStatus::Failed;
            }

            DB::transaction(function () use ($scan, $result, $byImpact, $status): void {
                $this->persistFindings($scan, $result->findings);

                $scan->update([
                    'status' => $status,
                    'finished_at' => now(),
                    'urls_discovered' => $result->urlsDiscovered,
                    'urls_scanned' => $result->urlsScanned,
                    'violations_total' => count($result->findings),
                    'violations_by_impact' => $byImpact,
                    'failed_urls' => $result->failedUrls !== [] ? $result->failedUrls : null,
                ]);

                if ($status === ScanStatus::Completed) {
                    $scan->domain()
                        ->withoutGlobalScope('organisation')
                        ->update(['last_a11y_scanned_at' => now()]);
                }
            });

            if ($status !== ScanStatus::Failed) {
                try {
                    $this->alerts->alertIfNeeded($scan->refresh(), $domain);
                } catch (Throwable) {
                    // Best-effort; operator sees the scan in admin either way.
                }
            }
        } catch (Throwable $exception) {
            $scan->update([
                'status' => ScanStatus::Failed,
                'finished_at' => now(),
                'error_log' => [
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                ],
            ]);

            try {
                $domainAfterFailure = $scan->domain()
                    ->withoutGlobalScope('organisation')
                    ->first();
                if ($domainAfterFailure !== null) {
                    $this->alerts->alertOnFailure($scan, $domainAfterFailure, [
                        'message' => $exception->getMessage(),
                        'class' => $exception::class,
                    ]);
                }
            } catch (Throwable) {
                // Same swallow as the cookie scanner.
            }
        }

        return $scan->refresh();
    }

    /**
     * Per-chunk work, called from {@see ScanAccessibilityUrlsJob}.
     * Calls the worker for one chunk of URLs, inserts the resulting
     * findings, and atomically increments `urls_scanned`. Counters
     * (`violations_total`, `violations_by_impact`) are recomputed
     * from the persisted rows by {@see self::finaliseBatch} at the
     * end — easier than merging partial counts across chunks.
     *
     * @param  list<string>  $urls
     */
    public function scanUrlChunk(string $scanId, array $urls): void
    {
        if ($urls === []) {
            return;
        }

        /** @var AccessibilityScan|null $scan */
        $scan = AccessibilityScan::query()->withoutGlobalScope('organisation')->find($scanId);
        if ($scan === null) {
            return;
        }

        if ($scan->getAttribute('status') === ScanStatus::Completed
            || $scan->getAttribute('status') === ScanStatus::Failed) {
            return;
        }

        /** @var Domain $domain */
        $domain = $scan->domain()->withoutGlobalScope('organisation')->firstOrFail();

        $result = $this->scanner->scan(
            $domain,
            $urls,
            (string) $scan->getAttribute('organisation_id'),
            (string) $scan->getKey(),
        );

        $successCount = count($urls) - count($result->failedUrls);

        DB::transaction(function () use ($scan, $result, $successCount): void {
            $this->persistFindings($scan, $result->findings);

            if ($successCount > 0) {
                AccessibilityScan::query()
                    ->where('id', $scan->getKey())
                    ->increment('urls_scanned', $successCount);
            }

            if ($result->failedUrls !== []) {
                $this->appendFailedUrls($scan->getKey(), $result->failedUrls);
            }
        });
    }

    /**
     * Called from the Bus::batch `finally` hook. Recounts violations
     * from the persisted rows (sidesteps cross-chunk merge logic),
     * flips the scan to completed/failed, stamps the domain, and
     * triggers the success/failure alert symmetrically with
     * {@see self::execute()}.
     */
    public function finaliseBatch(string $scanId): void
    {
        /** @var AccessibilityScan|null $scan */
        $scan = AccessibilityScan::query()->withoutGlobalScope('organisation')->find($scanId);
        if ($scan === null) {
            return;
        }

        $currentStatus = $scan->getAttribute('status');
        if ($currentStatus === ScanStatus::Completed
            || $currentStatus === ScanStatus::Partial
            || $currentStatus === ScanStatus::Failed) {
            return;
        }

        /** @var Domain $domain */
        $domain = $scan->domain()->withoutGlobalScope('organisation')->firstOrFail();

        $urlsDiscovered = (int) $scan->getAttribute('urls_discovered');
        $urlsScanned = (int) $scan->getAttribute('urls_scanned');
        $failedCount = count((array) $scan->getAttribute('failed_urls'));

        /** @var array<string, int> $byImpact */
        $byImpact = $this->recountByImpact($scanId);
        $violationsTotal = array_sum($byImpact);

        // Tri-state: full success, partial (some URLs landed, some
        // failed — retry button available), or hard failure (nothing
        // scanned).
        if ($urlsScanned >= $urlsDiscovered && $failedCount === 0) {
            $status = ScanStatus::Completed;
            $errorLog = null;
        } elseif ($urlsScanned > 0) {
            $status = ScanStatus::Partial;
            $errorLog = [
                'class' => 'PartialBatchFailure',
                'message' => sprintf(
                    '%d of %d URLs failed to scan — see Horizon failed jobs for per-URL detail.',
                    $urlsDiscovered - $urlsScanned,
                    $urlsDiscovered,
                ),
            ];
        } else {
            $status = ScanStatus::Failed;
            $errorLog = [
                'class' => 'BatchFailure',
                'message' => sprintf('All %d URLs failed to scan.', $urlsDiscovered),
            ];
        }

        DB::transaction(function () use ($scan, $domain, $status, $errorLog, $byImpact, $violationsTotal): void {
            $scan->update([
                'status' => $status,
                'finished_at' => now(),
                'error_log' => $errorLog,
                'violations_total' => $violationsTotal,
                'violations_by_impact' => $byImpact,
            ]);

            if ($status === ScanStatus::Completed) {
                $domain->update(['last_a11y_scanned_at' => now()]);
            }
        });

        if ($status === ScanStatus::Completed || $status === ScanStatus::Partial) {
            try {
                $this->alerts->alertIfNeeded($scan->refresh(), $domain);
            } catch (Throwable) {
                // Best-effort.
            }
        } else {
            try {
                $this->alerts->alertOnFailure($scan, $domain, $errorLog);
            } catch (Throwable) {
                // Best-effort.
            }
        }
    }

    private function createScan(
        Domain $domain,
        ScanTrigger $trigger,
        ?User $triggeredBy,
        int $urlsDiscovered,
    ): AccessibilityScan {
        return AccessibilityScan::create([
            'domain_id' => $domain->getKey(),
            'status' => ScanStatus::Queued,
            'trigger' => $trigger,
            'created_by_user_id' => $triggeredBy?->getKey(),
            'urls_discovered' => $urlsDiscovered,
        ]);
    }

    /**
     * Re-runs only the URLs that previously failed on this scan.
     * Mirror of {@see \App\Services\Scanner\ScanRunner::retryFailed}.
     */
    public function retryFailed(AccessibilityScan $scan): void
    {
        $failed = array_values((array) $scan->getAttribute('failed_urls'));
        if ($failed === []) {
            return;
        }

        DB::transaction(function () use ($scan): void {
            $scan->update([
                'status' => ScanStatus::Running,
                'failed_urls' => null,
                'error_log' => null,
            ]);
        });

        $scanId = $scan->getKey();
        $chunkSize = max(1, (int) config('services.scanner.url_chunk_size', 5));
        $chunks = array_chunk($failed, $chunkSize);

        $jobs = array_map(
            static fn (array $chunk): ScanAccessibilityUrlsJob => new ScanAccessibilityUrlsJob($scanId, $chunk),
            $chunks,
        );

        Bus::batch($jobs)
            ->name(sprintf('a11y-scan-retry:%s', $scanId))
            ->allowFailures()
            ->finally(static function () use ($scanId): void {
                app(AccessibilityScanRunner::class)->finaliseBatch($scanId);
            })
            ->onQueue('accessibility-scans')
            ->dispatch();
    }

    /**
     * Called from {@see ScanAccessibilityUrlsJob::failed()} when retries
     * are exhausted. Mirror of the cookie runner.
     *
     * @param  list<string>  $urls
     */
    public function recordChunkFailure(string $scanId, array $urls, Throwable $exception): void
    {
        DB::transaction(function () use ($scanId, $urls, $exception): void {
            $this->appendFailedUrls($scanId, $urls);

            AccessibilityScan::query()
                ->withoutGlobalScope('organisation')
                ->where('id', $scanId)
                ->update(['error_log' => [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                ]]);
        });
    }

    /**
     * Read-modify-write of the scan's failed_urls JSON column. Must be
     * called inside an outer DB transaction so concurrent chunk jobs
     * don't lose entries — the row-level lock serialises the merge.
     *
     * @param  list<string>  $urls
     */
    private function appendFailedUrls(string $scanId, array $urls): void
    {
        /** @var AccessibilityScan|null $locked */
        $locked = AccessibilityScan::query()
            ->withoutGlobalScope('organisation')
            ->where('id', $scanId)
            ->lockForUpdate()
            ->first();
        if ($locked === null) {
            return;
        }

        $existing = (array) $locked->getAttribute('failed_urls');
        $merged = array_values(array_unique([...$existing, ...$urls]));
        $locked->update(['failed_urls' => $merged]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function persistFindings(AccessibilityScan $scan, array $findings): void
    {
        foreach ($findings as $finding) {
            $rawNodes = is_array($finding['nodes'] ?? null) ? array_values($finding['nodes']) : [];

            // The worker uploads element PNGs straight to R2 and returns
            // the key on the first node. We persist that key on the row
            // (ShowFindingScreenshot streams the bytes from the same
            // bucket on demand) and strip it off the nodes JSON to keep
            // the column lean.
            $screenshotKey = $this->extractScreenshotKey($rawNodes);

            AccessibilityFinding::create([
                'accessibility_scan_id' => $scan->getKey(),
                'rule_id' => (string) $finding['rule_id'],
                'impact' => $this->coerceImpact($finding['impact']),
                'description' => (string) $finding['description'],
                'help_text' => (string) $finding['help_text'],
                'help_url' => (string) $finding['help_url'],
                'url' => (string) $finding['url'],
                'tags' => is_array($finding['tags'] ?? null) ? array_values($finding['tags']) : [],
                'nodes' => $rawNodes,
                'screenshot_key' => $screenshotKey,
            ]);
        }
    }

    /**
     * Pulls the R2 screenshot key off the first node (if present) and
     * removes it from the node JSON. Returns null when no key was set.
     *
     * @param  array<int, array<string, mixed>>  $nodes  passed by reference, mutated in place
     */
    private function extractScreenshotKey(array &$nodes): ?string
    {
        if (! isset($nodes[0]['screenshotKey']) || ! is_string($nodes[0]['screenshotKey'])) {
            return null;
        }

        $key = $nodes[0]['screenshotKey'];
        unset($nodes[0]['screenshotKey']);

        return $key === '' ? null : $key;
    }

    private function coerceImpact(mixed $value): A11yImpact
    {
        if ($value instanceof A11yImpact) {
            return $value;
        }

        $candidate = is_string($value) ? A11yImpact::tryFrom($value) : null;

        // axe-core occasionally emits null impact for advisory rules.
        // Default to Minor — least severe, won't trip the Slack threshold.
        return $candidate ?? A11yImpact::Minor;
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<string, int>
     */
    private function countByImpact(array $findings): array
    {
        $counts = $this->zeroImpactCounts();

        foreach ($findings as $finding) {
            $impact = $this->coerceImpact($finding['impact'] ?? null)->value;
            $counts[$impact]++;
        }

        return $counts;
    }

    /**
     * Rebuilds the impact histogram from persisted rows. Cheaper than
     * carrying per-chunk merges through the batch and always correct
     * regardless of how the chunks landed.
     *
     * @return array<string, int>
     */
    private function recountByImpact(string $scanId): array
    {
        $counts = $this->zeroImpactCounts();

        /** @var array<int, object{impact: string|null, total: int}> $rows */
        $rows = AccessibilityFinding::query()
            ->withoutGlobalScope('organisation')
            ->where('accessibility_scan_id', $scanId)
            ->selectRaw('impact, COUNT(*) AS total')
            ->groupBy('impact')
            ->get()
            ->all();

        foreach ($rows as $row) {
            // `selectRaw('impact, COUNT(*) ...')` bypasses the model's
            // enum cast on `impact` for the aggregated row — depending on
            // the driver it lands as the raw string or the cast enum, so
            // we accept either shape.
            $rawImpact = $row->getAttribute('impact');
            $impact = $rawImpact instanceof A11yImpact ? $rawImpact->value : (string) $rawImpact;
            if ($impact === '' || ! array_key_exists($impact, $counts)) {
                continue;
            }
            $counts[$impact] = (int) $row->getAttribute('total');
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function zeroImpactCounts(): array
    {
        return [
            A11yImpact::Critical->value => 0,
            A11yImpact::Serious->value => 0,
            A11yImpact::Moderate->value => 0,
            A11yImpact::Minor->value => 0,
        ];
    }
}
