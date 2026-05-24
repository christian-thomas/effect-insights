<?php

declare(strict_types=1);

namespace App\Services\Accessibility;

use App\Enums\A11yImpact;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\RunAccessibilityScanJob;
use App\Models\AccessibilityFinding;
use App\Models\AccessibilityScan;
use App\Models\Domain;
use App\Models\User;
use App\Services\Notifications\AccessibilityAlertService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates a single accessibility scan. Mirrors the dispatch /
 * execute / run shape of {@see \App\Services\Scanner\ScanRunner} so
 * the two scanners look the same to anyone debugging either pipeline.
 *
 * The three entry points:
 *
 *   - {@see self::dispatch()}  Create a queued AccessibilityScan and
 *                              hand off to {@see RunAccessibilityScanJob}.
 *                              HTTP requests stay non-blocking.
 *   - {@see self::execute()}   Run the work in-place on an existing
 *                              scan row — flips running, calls the
 *                              scanner, persists findings, flips
 *                              completed/failed.
 *   - {@see self::run()}       create + execute. Used by the
 *                              `accessibility:scan-domain` artisan
 *                              command and PHPUnit feature tests.
 *
 * After a successful execute(), the alert service decides whether to
 * fire the Slack notification based on the impact threshold.
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
        $scan = $this->createScan($domain, $trigger, $triggeredBy);

        dispatch(new RunAccessibilityScanJob($scan->getKey(), array_values($urls)));

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
        $scan = $this->createScan($domain, $trigger, $triggeredBy);

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

            $result = $this->scanner->scan($domain, $urls);

            $byImpact = $this->countByImpact($result->findings);

            DB::transaction(function () use ($scan, $result, $byImpact): void {
                $this->persistFindings($scan, $result);

                $scan->update([
                    'status' => ScanStatus::Completed,
                    'finished_at' => now(),
                    'urls_discovered' => $result->urlsDiscovered,
                    'urls_scanned' => $result->urlsScanned,
                    'violations_total' => count($result->findings),
                    'violations_by_impact' => $byImpact,
                ]);

                $scan->domain()
                    ->withoutGlobalScope('organisation')
                    ->update(['last_a11y_scanned_at' => now()]);
            });

            // Fire-and-forget — alert delivery is not part of the
            // canonical scan record, so a Slack hiccup must not roll
            // back what we just persisted.
            try {
                $this->alerts->alertIfNeeded($scan->refresh(), $domain);
            } catch (Throwable) {
                // Best-effort; operator sees the scan in admin either way.
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

            // Reuse the existing scan-failure pipeline shape: the same
            // Slack channel sees a familiar alert format for both
            // verticals. Best-effort guard same as the cookie scanner.
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

    private function createScan(Domain $domain, ScanTrigger $trigger, ?User $triggeredBy): AccessibilityScan
    {
        return AccessibilityScan::create([
            'domain_id' => $domain->getKey(),
            'status' => ScanStatus::Queued,
            'trigger' => $trigger,
            'created_by_user_id' => $triggeredBy?->getKey(),
        ]);
    }

    private function persistFindings(AccessibilityScan $scan, AccessibilityScannerResult $result): void
    {
        foreach ($result->findings as $finding) {
            AccessibilityFinding::create([
                'accessibility_scan_id' => $scan->getKey(),
                'rule_id' => (string) ($finding['rule_id'] ?? ''),
                'impact' => $this->coerceImpact($finding['impact'] ?? null),
                'description' => (string) ($finding['description'] ?? ''),
                'help_text' => (string) ($finding['help_text'] ?? ''),
                'help_url' => (string) ($finding['help_url'] ?? ''),
                'url' => (string) ($finding['url'] ?? ''),
                'tags' => is_array($finding['tags'] ?? null) ? array_values($finding['tags']) : [],
                'nodes' => is_array($finding['nodes'] ?? null) ? array_values($finding['nodes']) : [],
            ]);
        }
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
        $counts = [
            A11yImpact::Critical->value => 0,
            A11yImpact::Serious->value => 0,
            A11yImpact::Moderate->value => 0,
            A11yImpact::Minor->value => 0,
        ];

        foreach ($findings as $finding) {
            $impact = $this->coerceImpact($finding['impact'] ?? null)->value;
            $counts[$impact]++;
        }

        return $counts;
    }
}
