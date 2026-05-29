<?php

declare(strict_types=1);

namespace App\Services\Scanner;

use App\Enums\FindingParty;
use App\Enums\FindingType;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanUrlsJob;
use App\Models\Domain;
use App\Models\Scan;
use App\Models\ScanFinding;
use App\Models\User;
use App\Services\Cookies\CookieCategoryResolver;
use App\Services\Notifications\ScanFailureAlertService;
use App\Services\Notifications\UnknownCookieAlertService;
use App\Support\Cookies\CookieResolution;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates a single domain scan. Four entry points:
 *
 *   - {@see self::dispatch()}        Create a Scan with `urls_discovered`
 *                                    pre-set, flip it to `running`, and
 *                                    dispatch a {@see Bus::batch()} of
 *                                    {@see ScanUrlsJob}s — one per chunk
 *                                    of `services.scanner.url_chunk_size`
 *                                    URLs — so the operator sees
 *                                    `urls_scanned` tick up live.
 *                                    Finalisation (status flip, alerts)
 *                                    lives in {@see self::finaliseBatch}.
 *   - {@see self::scanUrlChunk()}    Per-chunk work, called from
 *                                    {@see ScanUrlsJob}. Idempotent
 *                                    finding inserts via the unique
 *                                    (scan_id, type, name_hash) index.
 *   - {@see self::run()}             Synchronous create-and-execute path
 *                                    — used by tests and the
 *                                    `scans:run` artisan command.
 *   - {@see self::execute()}         Synchronous one-shot: flip running,
 *                                    scan all URLs in a single worker
 *                                    call, persist findings, flip
 *                                    completed/failed. Kept so the test
 *                                    suite doesn't have to fake job
 *                                    batches everywhere.
 *
 * Findings carry a category resolved through {@see CookieCategoryResolver}.
 * Unknown cookies land with category = NULL — surfaces in the manual
 * review queue.
 */
final readonly class ScanRunner
{
    public function __construct(
        private ScannerClientContract $scanner,
        private CookieCategoryResolver $resolver,
        private UnknownCookieAlertService $unknownCookieAlerts,
        private ScanFailureAlertService $scanFailureAlerts,
    ) {}

    /**
     * @param  array<int, string>  $urls
     */
    public function dispatch(
        Domain $domain,
        array $urls,
        ScanTrigger $trigger = ScanTrigger::Manual,
        ?User $triggeredBy = null,
    ): Scan {
        $urlList = array_values($urls);
        $scan = $this->createScan($domain, $trigger, $triggeredBy, count($urlList));

        // No URLs to scan (apex-less domain, empty sitemap, etc.) — finalise
        // immediately so the operator doesn't see a hanging "running" row.
        if ($urlList === []) {
            $this->finaliseBatch($scan->getKey());

            return $scan->refresh();
        }

        // Flip running up-front so the admin shows activity the moment
        // the user clicks. The per-URL jobs will tick urls_scanned as
        // they finish.
        $scan->update([
            'status' => ScanStatus::Running,
            'started_at' => now(),
        ]);

        $scanId = $scan->getKey();
        $chunkSize = max(1, (int) config('services.scanner.url_chunk_size', 5));
        $chunks = array_chunk($urlList, $chunkSize);

        $jobs = array_map(
            static fn (array $chunk): ScanUrlsJob => new ScanUrlsJob($scanId, $chunk),
            $chunks,
        );

        Bus::batch($jobs)
            ->name(sprintf('scan:%s', $scanId))
            // One URL failing must not abort the rest of the batch —
            // partial coverage is better than no coverage.
            ->allowFailures()
            ->finally(static function () use ($scanId): void {
                app(ScanRunner::class)->finaliseBatch($scanId);
            })
            ->onQueue('scans')
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
    ): Scan {
        $scan = $this->createScan($domain, $trigger, $triggeredBy, count(array_values($urls)));

        return $this->execute($scan, $urls);
    }

    /**
     * @param  array<int, string>  $urls
     */
    public function execute(Scan $scan, array $urls): Scan
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

            // Tri-state: same decision tree as finaliseBatch.
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

            DB::transaction(function () use ($scan, $domain, $result, $status): void {
                $this->persistFindings($scan, $domain, $result);

                $scan->update([
                    'status' => $status,
                    'finished_at' => now(),
                    'urls_discovered' => $result->urlsDiscovered,
                    'urls_scanned' => $result->urlsScanned,
                    'failed_urls' => $result->failedUrls !== [] ? $result->failedUrls : null,
                ]);

                // Powers the scheduled-scan "is due today" check — only
                // on a clean completion, not on partial coverage.
                if ($status === ScanStatus::Completed) {
                    $domain->update(['last_scanned_at' => now()]);
                }
            });

            // Fire-and-forget alert if the scan turned up cookies the
            // resolver couldn't categorise. Runs on Completed + Partial
            // (the URLs that did succeed are real findings). Notifier
            // failures must not propagate — they'd roll back the scan
            // we just persisted.
            if ($status !== ScanStatus::Failed) {
                try {
                    $this->unknownCookieAlerts->alertIfNeeded($scan, $domain);
                } catch (Throwable) {
                    // Best-effort; alert delivery is not part of the canonical record.
                }
            }
        } catch (Throwable $exception) {
            $error = [
                'message' => $exception->getMessage(),
                'class' => $exception::class,
            ];

            $scan->update([
                'status' => ScanStatus::Failed,
                'finished_at' => now(),
                'error_log' => $error,
            ]);

            // Best-effort failure alert — alerter problems must not mask
            // the original scan exception that we've already persisted.
            try {
                /** @var Domain|null $failedDomain */
                $failedDomain = $scan->domain()->withoutGlobalScope('organisation')->first();
                if ($failedDomain !== null) {
                    $this->scanFailureAlerts->alert($scan, $failedDomain, $error);
                }
            } catch (Throwable) {
                // Swallow — operator sees the status flip in admin either way.
            }
        }

        return $scan->refresh();
    }

    /**
     * Per-chunk work, called from {@see ScanUrlsJob}. Calls the worker
     * for one chunk of URLs (size controlled by
     * `services.scanner.url_chunk_size`), persists findings
     * idempotently (a cookie observed on multiple pages collapses to a
     * single row via the unique `(scan_id, type, name_hash)` index),
     * and atomically increments `urls_scanned` by the chunk size on the
     * parent Scan row.
     *
     * @param  list<string>  $urls
     */
    public function scanUrlChunk(string $scanId, array $urls): void
    {
        if ($urls === []) {
            return;
        }

        /** @var Scan|null $scan */
        $scan = Scan::query()->withoutGlobalScope('organisation')->find($scanId);
        if ($scan === null) {
            // Scan was deleted between dispatch and pickup — nothing to do.
            return;
        }

        if ($scan->getAttribute('status') === ScanStatus::Completed
            || $scan->getAttribute('status') === ScanStatus::Failed) {
            // Batch was cancelled / already finalised — don't write more findings.
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

        DB::transaction(function () use ($scan, $domain, $result, $successCount): void {
            $this->persistFindings($scan, $domain, $result);

            if ($successCount > 0) {
                // Atomic bump — increment only by URLs that actually
                // scanned, not the chunk size. Concurrent chunk jobs
                // can't lose increments via this primitive.
                Scan::query()
                    ->where('id', $scan->getKey())
                    ->increment('urls_scanned', $successCount);
            }

            if ($result->failedUrls !== []) {
                $this->appendFailedUrls($scan->getKey(), $result->failedUrls);
            }
        });
    }

    /**
     * Called from the Bus::batch `finally` hook. Reads the final
     * `urls_scanned` count and flips the scan to `completed` (full
     * coverage) or `failed` (some URLs blew up). Triggers alert
     * notifications symmetrically with {@see self::execute()}.
     */
    public function finaliseBatch(string $scanId): void
    {
        /** @var Scan|null $scan */
        $scan = Scan::query()->withoutGlobalScope('organisation')->find($scanId);
        if ($scan === null) {
            return;
        }

        $currentStatus = $scan->getAttribute('status');
        if ($currentStatus === ScanStatus::Completed
            || $currentStatus === ScanStatus::Partial
            || $currentStatus === ScanStatus::Failed) {
            // Already finalised (manual run, retry, or duplicate hook).
            return;
        }

        /** @var Domain $domain */
        $domain = $scan->domain()->withoutGlobalScope('organisation')->firstOrFail();

        $urlsDiscovered = (int) $scan->getAttribute('urls_discovered');
        $urlsScanned = (int) $scan->getAttribute('urls_scanned');
        $failedCount = count((array) $scan->getAttribute('failed_urls'));

        // Tri-state status: full success only when every URL landed and
        // nothing's on the failed list. Some success + some failures =
        // Partial (operator can retry the gaps). Nothing succeeded = Failed.
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

        DB::transaction(function () use ($scan, $domain, $status, $errorLog): void {
            $scan->update([
                'status' => $status,
                'finished_at' => now(),
                'error_log' => $errorLog,
            ]);

            // Stamp the domain only on full success — partial doesn't
            // satisfy the cadence ("we scanned this domain today").
            if ($status === ScanStatus::Completed) {
                $domain->update(['last_scanned_at' => now()]);
            }
        });

        if ($status === ScanStatus::Completed || $status === ScanStatus::Partial) {
            // Findings from the URLs that DID succeed are real — still
            // run the success-side alert. Partial doesn't fire the
            // failure alert; the admin retry button is the right channel.
            try {
                $this->unknownCookieAlerts->alertIfNeeded($scan, $domain);
            } catch (Throwable) {
                // Best-effort.
            }
        } else {
            try {
                $this->scanFailureAlerts->alert($scan, $domain, $errorLog);
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
    ): Scan {
        return Scan::create([
            'domain_id' => $domain->getKey(),
            'status' => ScanStatus::Queued,
            'trigger' => $trigger,
            'created_by_user_id' => $triggeredBy?->getKey(),
            'urls_discovered' => $urlsDiscovered,
        ]);
    }

    /**
     * Re-runs only the URLs that previously failed on this scan. Same
     * scan row, same chunk/Bus::batch wiring — `urls_scanned` resumes
     * ticking up from where it left off, findings merge into the existing
     * row, and `finaliseBatch` re-evaluates the status when the new
     * batch completes.
     */
    public function retryFailed(Scan $scan): void
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
            static fn (array $chunk): ScanUrlsJob => new ScanUrlsJob($scanId, $chunk),
            $chunks,
        );

        Bus::batch($jobs)
            ->name(sprintf('scan-retry:%s', $scanId))
            ->allowFailures()
            ->finally(static function () use ($scanId): void {
                app(ScanRunner::class)->finaliseBatch($scanId);
            })
            ->onQueue('scans')
            ->dispatch();
    }

    /**
     * Called from {@see ScanUrlsJob::failed()} when the chunk job has
     * exhausted all retry attempts on infra-level errors (worker
     * unreachable, 500, timeout, etc.). Records every URL in the chunk
     * as failed and stores the exception summary on the scan's
     * error_log so the operator has something to look at when they hit
     * "Retry failed URLs". `urls_scanned` is not changed — those URLs
     * never produced findings.
     *
     * @param  list<string>  $urls
     */
    public function recordChunkFailure(string $scanId, array $urls, Throwable $exception): void
    {
        DB::transaction(function () use ($scanId, $urls, $exception): void {
            $this->appendFailedUrls($scanId, $urls);

            Scan::query()
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
     * don't lose entries — the row-level lock from SELECT FOR UPDATE
     * serialises the merge.
     *
     * @param  list<string>  $urls
     */
    private function appendFailedUrls(string $scanId, array $urls): void
    {
        /** @var Scan|null $locked */
        $locked = Scan::query()
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

    private function persistFindings(Scan $scan, Domain $domain, ScannerResult $result): void
    {
        foreach ($result->cookies as $cookie) {
            // Pass the domain only — the resolver pulls organisation_id off
            // it, which is enough to scope override lookups correctly.
            $resolution = $this->resolver->resolve((string) $cookie['name'], $domain);

            $this->insertFinding($scan, FindingType::Cookie, [
                'name' => (string) $cookie['name'],
                'domain_set_on' => $cookie['domain'] ?? null,
                'value_pattern' => $cookie['value_pattern'] ?? null,
                'party' => $this->partyFor($cookie['party']),
                'first_seen_url' => $cookie['first_seen_url'] ?? null,
                'raw_data' => $cookie['raw'] ?? null,
            ], $resolution);
        }

        $this->persistAssets($scan, $result->scripts, FindingType::Script);
        $this->persistAssets($scan, $result->iframes, FindingType::Iframe);
        $this->persistAssets($scan, $result->requests, FindingType::NetworkRequest);
    }

    /**
     * @param  array<int, array<string, mixed>>  $assets
     */
    private function persistAssets(Scan $scan, array $assets, FindingType $type): void
    {
        foreach ($assets as $asset) {
            $this->insertFinding($scan, $type, [
                'name' => (string) ($asset['name'] ?? ''),
                'party' => $this->partyFor((string) ($asset['party'] ?? 'third')),
                'first_seen_url' => $asset['first_seen_url'] ?? null,
                'raw_data' => $asset['raw'] ?? null,
            ], null);
        }
    }

    /**
     * Idempotent insert. The unique index on (scan_id, type, name_hash)
     * means a second per-URL job that observed the same cookie/script
     * is a no-op rather than a duplicate row. `firstOrCreate` races
     * under concurrent inserts; we catch the race-loser's
     * unique-constraint exception and treat it as success. We dedupe on
     * a SHA-256 of `name` (rather than `name` itself) because finding
     * names can be 500+ char tracking URLs — too long for MySQL to use
     * in a multi-column index.
     *
     * @param  array<string, mixed>  $base
     */
    private function insertFinding(
        Scan $scan,
        FindingType $type,
        array $base,
        ?CookieResolution $resolution,
    ): void {
        $name = (string) ($base['name'] ?? '');

        try {
            ScanFinding::query()->firstOrCreate(
                [
                    'scan_id' => $scan->getKey(),
                    'type' => $type,
                    'name_hash' => hash('sha256', $name),
                ],
                [
                    'name' => $name,
                    'domain_set_on' => $base['domain_set_on'] ?? null,
                    'value_pattern' => $base['value_pattern'] ?? null,
                    'party' => $base['party'] ?? FindingParty::Third,
                    'category' => $resolution?->category,
                    'category_source' => $resolution?->source,
                    'purpose_text' => $resolution?->purpose,
                    'first_seen_url' => $base['first_seen_url'] ?? null,
                    'cookie_id' => $resolution?->cookieId,
                    'cookie_override_id' => $resolution?->overrideId,
                    'raw_data' => $base['raw_data'] ?? null,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // Concurrent insert from another URL job — that row already
            // exists, which is exactly the dedupe we wanted.
        }
    }

    private function partyFor(string $value): FindingParty
    {
        return $value === 'first' ? FindingParty::First : FindingParty::Third;
    }
}
