<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Scans;

use App\Enums\ScanTrigger;
use App\Http\Controllers\Controller;
use App\Http\Resources\ScanResource;
use App\Models\Domain;
use App\Models\User;
use App\Services\Scanner\ScanRunner;
use App\Services\Scanner\SitemapDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TriggerScan extends Controller
{
    public function __invoke(
        Request $request,
        Domain $domain,
        ScanRunner $runner,
        SitemapDiscoveryService $discovery,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        // Sitemap-driven URL list; falls back to apex-only when the domain
        // has no discoverable sitemap.
        $urls = $discovery->discover($domain);

        // Returns immediately with the running Scan; the actual crawl
        // fans out into ScanUrlsJob chunks via Bus::batch so the admin
        // sees `urls_scanned` tick up live (chunk size: see
        // services.scanner.url_chunk_size).
        $scan = $runner->dispatch($domain, $urls, ScanTrigger::Manual, $user);

        return ScanResource::make($scan)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
