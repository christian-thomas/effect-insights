<?php

declare(strict_types=1);

namespace App\Services\Accessibility;

use App\Models\Domain;
use Tests\TenantTestCase;

/**
 * Contract for whichever scanner implementation actually crawls a list
 * of URLs and runs axe-core. Two production implementations live in
 * this namespace:
 *
 * - {@see HttpA11yScanner} — posts to the dedicated CF scanner worker
 * - {@see ArrayA11yScanner} — bound by {@see TenantTestCase} so PHPUnit
 *   never makes a real Browser Rendering call. Tests preload findings
 *   via setNext().
 */
interface AccessibilityScannerClientContract
{
    /**
     * @param  array<int, string>  $urls
     */
    public function scan(Domain $domain, array $urls): AccessibilityScannerResult;
}
