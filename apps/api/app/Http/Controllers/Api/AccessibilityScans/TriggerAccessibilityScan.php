<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AccessibilityScans;

use App\Enums\ScanTrigger;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccessibilityScanResource;
use App\Models\Domain;
use App\Models\User;
use App\Services\Accessibility\AccessibilityScanRunner;
use App\Services\Scanner\SitemapDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manual trigger for an accessibility scan against a specific domain.
 * Reuses {@see SitemapDiscoveryService} for URL discovery — the same
 * cookie scanner uses it, so a domain's URL list is consistent
 * between the two verticals (axe always sees what the cookie scanner
 * sees, even if the cadences are different).
 */
class TriggerAccessibilityScan extends Controller
{
    public function __invoke(
        Request $request,
        Domain $domain,
        AccessibilityScanRunner $runner,
        SitemapDiscoveryService $discovery,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $urls = $discovery->discover($domain);

        // Returns immediately with the running scan; the actual axe
        // crawl fans out into ScanAccessibilityUrlsJob chunks via
        // Bus::batch so the admin sees `urls_scanned` tick up live.
        $scan = $runner->dispatch($domain, $urls, ScanTrigger::Manual, $user);

        return AccessibilityScanResource::make($scan)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
