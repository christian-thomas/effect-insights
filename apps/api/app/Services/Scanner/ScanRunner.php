<?php

declare(strict_types=1);

namespace App\Services\Scanner;

use App\Enums\FindingParty;
use App\Enums\FindingType;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\RunScanJob;
use App\Models\Domain;
use App\Models\Scan;
use App\Models\ScanFinding;
use App\Models\User;
use App\Services\Cookies\CookieCategoryResolver;
use App\Support\Cookies\CookieResolution;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates a single domain scan. Three entry points:
 *
 *   - {@see self::dispatch()}  Create a queued Scan and hand it off to
 *                              {@see RunScanJob}. Returns immediately so
 *                              the HTTP request doesn't block.
 *   - {@see self::execute()}   Run the work in-place on an existing
 *                              Scan — flip running, call the scanner,
 *                              persist findings, flip completed/failed.
 *                              Called by RunScanJob and by sync paths.
 *   - {@see self::run()}       Convenience: create + execute. Used by
 *                              the `scans:run` artisan command.
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
        $scan = $this->createScan($domain, $trigger, $triggeredBy);

        dispatch(new RunScanJob($scan->getKey(), array_values($urls)));

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
        $scan = $this->createScan($domain, $trigger, $triggeredBy);

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

            $result = $this->scanner->scan($domain, $urls);

            DB::transaction(function () use ($scan, $domain, $result): void {
                $this->persistFindings($scan, $domain, $result);

                $scan->update([
                    'status' => ScanStatus::Completed,
                    'finished_at' => now(),
                    'urls_discovered' => $result->urlsDiscovered,
                    'urls_scanned' => $result->urlsScanned,
                ]);

                // Powers the scheduled-scan "is due today" check — see
                // App\Console\Commands\DispatchScheduledScans.
                $domain->update(['last_scanned_at' => now()]);
            });
        } catch (Throwable $exception) {
            $scan->update([
                'status' => ScanStatus::Failed,
                'finished_at' => now(),
                'error_log' => [
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                ],
            ]);
        }

        return $scan->refresh();
    }

    private function createScan(Domain $domain, ScanTrigger $trigger, ?User $triggeredBy): Scan
    {
        return Scan::create([
            'domain_id' => $domain->getKey(),
            'status' => ScanStatus::Queued,
            'trigger' => $trigger,
            'created_by_user_id' => $triggeredBy?->getKey(),
        ]);
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
     * @param  array<string, mixed>  $base
     */
    private function insertFinding(
        Scan $scan,
        FindingType $type,
        array $base,
        ?CookieResolution $resolution,
    ): void {
        ScanFinding::create([
            'scan_id' => $scan->getKey(),
            'type' => $type,
            'name' => $base['name'] ?? '',
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
        ]);
    }

    private function partyFor(string $value): FindingParty
    {
        return $value === 'first' ? FindingParty::First : FindingParty::Third;
    }
}
