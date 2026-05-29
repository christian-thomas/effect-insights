<?php

declare(strict_types=1);

namespace App\Services\Scanner;

use App\Models\Domain;

/**
 * Abstraction over whatever crawler we point at a domain. The default
 * implementation talks to Cloudflare Browser Rendering's REST API; tests
 * bind {@see ArrayScannerClient} which returns canned findings without
 * leaving the test process.
 *
 * The contract exists so we can swap in a Node + Playwright fallback
 * later (per the v2 plan) without touching the scan orchestration layer.
 */
interface ScannerClientContract
{
    /**
     * @param  array<int, string>  $urls
     * @param  string  $organisationId  Used by the worker to scope R2 keys
     *                                  (only consumed by /scan-a11y today,
     *                                  but passed uniformly for both).
     * @param  string  $scanId  The Scan UUID; same scoping reason.
     */
    public function scan(Domain $domain, array $urls, string $organisationId, string $scanId): ScannerResult;
}
