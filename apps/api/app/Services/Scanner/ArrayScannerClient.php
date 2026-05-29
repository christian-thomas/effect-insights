<?php

declare(strict_types=1);

namespace App\Services\Scanner;

use App\Models\Domain;

/**
 * In-memory scanner that returns whatever findings the test (or a manual
 * dev workflow) preloaded. The default constructor returns an empty
 * result. Use {@see self::withCookies()}, {@see self::withScripts()}, etc.
 * to seed specific findings.
 */
final class ArrayScannerClient implements ScannerClientContract
{
    private ScannerResult $next;

    public function __construct(?ScannerResult $next = null)
    {
        $this->next = $next ?? new ScannerResult(0, 0, [], [], [], []);
    }

    public function setNext(ScannerResult $result): void
    {
        $this->next = $result;
    }

    /**
     * @param  array<int, string>  $urls
     */
    #[\Override]
    public function scan(Domain $domain, array $urls, string $organisationId, string $scanId): ScannerResult
    {
        return $this->next;
    }
}
