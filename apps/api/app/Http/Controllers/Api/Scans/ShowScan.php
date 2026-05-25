<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Scans;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScanFindingResource;
use App\Http\Resources\ScanResource;
use App\Models\Scan;
use App\Services\Scanner\ScanDiffService;
use Illuminate\Http\JsonResponse;

class ShowScan extends Controller
{
    public function __invoke(Scan $scan, ScanDiffService $diffs): JsonResponse
    {
        $findings = $scan->findings()
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return new JsonResponse([
            'scan' => ScanResource::make($scan),
            'findings' => ScanFindingResource::collection($findings),
            // Cookie-policy diff vs the previous completed scan on this
            // domain. Null when no prior scan exists.
            'diff' => $diffs->compare($scan),
        ]);
    }
}
