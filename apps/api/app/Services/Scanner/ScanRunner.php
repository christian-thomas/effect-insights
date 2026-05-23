<?php

declare(strict_types=1);

namespace App\Services\Scanner;

use App\Enums\FindingParty;
use App\Enums\FindingType;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Domain;
use App\Models\Scan;
use App\Models\ScanFinding;
use App\Models\User;
use App\Services\Cookies\CookieCategoryResolver;
use App\Support\Cookies\CookieResolution;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates a single domain scan:
 *
 *   1. Create a `Scan` row in status `queued`.
 *   2. Flip to `running` + record started_at.
 *   3. Ask the {@see ScannerClientContract} to scan the URLs.
 *   4. Persist each observed cookie / script / iframe / request as a
 *      {@see ScanFinding}, resolving the category via
 *      {@see CookieCategoryResolver} so the manual review queue picks
 *      up anything unknown.
 *   5. Flip to `completed` (or `failed` on exception) + record finished_at
 *      and counters.
 *
 * MVP runs synchronously — the artisan command + admin trigger both
 * block until the scan finishes. Queue dispatch lands as a follow-up
 * once we have real CF Browser Rendering traffic to throttle.
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
    public function run(
        Domain $domain,
        array $urls,
        ScanTrigger $trigger = ScanTrigger::Manual,
        ?User $triggeredBy = null,
    ): Scan {
        $scan = Scan::create([
            'domain_id' => $domain->getKey(),
            'status' => ScanStatus::Queued,
            'trigger' => $trigger,
            'created_by_user_id' => $triggeredBy?->getKey(),
        ]);

        $scan->update([
            'status' => ScanStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $result = $this->scanner->scan($domain, $urls);

            DB::transaction(function () use ($scan, $domain, $result): void {
                $this->persistFindings($scan, $domain, $result);

                $scan->update([
                    'status' => ScanStatus::Completed,
                    'finished_at' => now(),
                    'urls_discovered' => $result->urlsDiscovered,
                    'urls_scanned' => $result->urlsScanned,
                ]);
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
