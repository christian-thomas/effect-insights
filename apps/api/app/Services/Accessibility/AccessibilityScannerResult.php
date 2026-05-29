<?php

declare(strict_types=1);

namespace App\Services\Accessibility;

use App\Services\Scanner\ScannerResult;

/**
 * Normalised shape returned by every {@see AccessibilityScannerClientContract}.
 * Mirrors the cookie scanner's {@see ScannerResult}
 * pattern but with axe-core's violation shape instead of cookie metadata.
 *
 * @phpstan-type ObservedFinding array{
 *   rule_id: string,
 *   impact: string,
 *   description: string,
 *   help_text: string,
 *   help_url: string,
 *   url: string,
 *   tags?: array<int, string>,
 *   nodes?: array<int, array<string, mixed>>,
 *   raw?: array<string, mixed>,
 * }
 */
final readonly class AccessibilityScannerResult
{
    /**
     * @param  array<int, ObservedFinding>  $findings
     * @param  list<string>  $failedUrls  URLs the worker tried + gave up on
     */
    public function __construct(
        public int $urlsDiscovered,
        public int $urlsScanned,
        public array $findings,
        public array $failedUrls = [],
    ) {}
}
