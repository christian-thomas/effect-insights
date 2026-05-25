<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\CookieImports;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Cookies\CookieImportPromoter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bulk-promote every pending cookie import into the canonical DB.
 * Iteration + per-row error handling lives in
 * {@see CookieImportPromoter::promoteAllPending}.
 */
class PromoteAllCookieImports extends Controller
{
    public function __invoke(Request $request, CookieImportPromoter $promoter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $promoter->promoteAllPending($user),
        ]);
    }
}
