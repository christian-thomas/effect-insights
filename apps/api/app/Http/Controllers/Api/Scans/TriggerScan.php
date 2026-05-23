<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Scans;

use App\Enums\ScanTrigger;
use App\Http\Controllers\Controller;
use App\Http\Resources\ScanResource;
use App\Models\Domain;
use App\Models\User;
use App\Services\Scanner\ScanRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TriggerScan extends Controller
{
    public function __invoke(
        Request $request,
        Domain $domain,
        ScanRunner $runner,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $urls = [sprintf('https://%s/', $domain->getAttribute('fqdn'))];

        // MVP runs synchronously inside the request. Queue dispatch lands
        // once we have real CF Browser Rendering traffic to throttle.
        $scan = $runner->run($domain, $urls, ScanTrigger::Manual, $user);

        return ScanResource::make($scan)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
