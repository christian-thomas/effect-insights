<?php

declare(strict_types=1);

namespace App\Services\Scanner;

/**
 * Normalised shape returned by every {@see ScannerClientContract}.
 * Keep this stable — the scan persistence layer assumes it.
 *
 * @phpstan-type ObservedCookie array{name: string, domain: string|null, value_pattern?: string, party: string, first_seen_url?: string, raw?: array<string, mixed>}
 * @phpstan-type ObservedAsset array{name: string, party: string, first_seen_url?: string, raw?: array<string, mixed>}
 */
final readonly class ScannerResult
{
    /**
     * @param  array<int, ObservedCookie>  $cookies
     * @param  array<int, ObservedAsset>  $scripts
     * @param  array<int, ObservedAsset>  $iframes
     * @param  array<int, ObservedAsset>  $requests
     * @param  list<string>  $failedUrls  URLs the worker tried + gave up on
     */
    public function __construct(
        public int $urlsDiscovered,
        public int $urlsScanned,
        public array $cookies,
        public array $scripts,
        public array $iframes,
        public array $requests,
        public array $failedUrls = [],
    ) {}
}
