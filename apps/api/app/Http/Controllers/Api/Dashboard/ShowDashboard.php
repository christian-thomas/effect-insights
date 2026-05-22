<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;

class ShowDashboard extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'domains' => Domain::query()->count(),
                // Scans + consent events land in iterations 4/5; surfacing the
                // shape now so the frontend doesn't need to change when they do.
                'scans' => 0,
                'consentEvents' => 0,
            ],
        ]);
    }
}
