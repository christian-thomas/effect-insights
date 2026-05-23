<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Scans;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScanFindingResource;
use App\Http\Resources\ScanResource;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;

class ShowScan extends Controller
{
    public function __invoke(Scan $scan): JsonResponse
    {
        $findings = $scan->findings()
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json([
            'scan' => ScanResource::make($scan),
            'findings' => ScanFindingResource::collection($findings),
        ]);
    }
}
