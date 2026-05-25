<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AccessibilityScan;
use App\Models\ConsentEvent;
use App\Models\Domain;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;

class ShowDashboard extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'domains' => Domain::query()->count(),
                'cookieScans' => Scan::query()->count(),
                'consentEvents' => ConsentEvent::query()->count(),
                'accessibilityScans' => AccessibilityScan::query()->count(),
            ],
        ]);
    }
}
