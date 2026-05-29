<?php

declare(strict_types=1);

namespace App\Services\Accessibility;

use App\Models\Domain;
use App\Services\Scanner\ArrayScannerClient;

/**
 * In-memory accessibility scanner that returns whatever findings the
 * test (or a manual dev workflow) preloaded. Default constructor
 * returns an empty result. Use {@see self::setNext()} to seed.
 *
 * Mirrors {@see ArrayScannerClient} so tests look
 * the same across the cookie and a11y pipelines.
 */
final class ArrayA11yScanner implements AccessibilityScannerClientContract
{
    private AccessibilityScannerResult $next;

    public function __construct(?AccessibilityScannerResult $next = null)
    {
        $this->next = $next ?? new AccessibilityScannerResult(0, 0, []);
    }

    public function setNext(AccessibilityScannerResult $result): void
    {
        $this->next = $result;
    }

    /**
     * @param  array<int, string>  $urls
     */
    #[\Override]
    public function scan(
        Domain $domain,
        array $urls,
        string $organisationId,
        string $scanId,
    ): AccessibilityScannerResult {
        return $this->next;
    }
}
