<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\CookieImports;

use App\Enums\CookieImportStatus;
use App\Http\Controllers\Controller;
use App\Models\CookieImport;
use App\Models\User;
use App\Services\Cookies\CookieImportPromoter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Bulk-promote every pending cookie import into the canonical DB. Used
 * after an upload of a vetted catalogue (e.g. a full Open-Cookie-Database
 * release) where individual review isn't worth the click cost.
 *
 * Each promotion is wrapped in its own try/catch — a single row failing
 * (unique-key collision, missing field, etc) doesn't abort the rest.
 * Returns aggregate counts plus the first error message if any.
 */
class PromoteAllCookieImports extends Controller
{
    public function __invoke(
        Request $request,
        CookieImportPromoter $promoter,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $promoted = 0;
        $failed = 0;
        $firstError = null;

        // Process in chunks of 200 to avoid hydrating thousands of models
        // at once. The caller may submit large OCD releases (2 000+ rows)
        // so keeping a bounded memory profile matters more than batching
        // into a single DB transaction.
        CookieImport::query()
            ->where('status', CookieImportStatus::Pending)->oldest()
            ->chunk(200, function (Collection $imports) use ($promoter, $user, &$promoted, &$failed, &$firstError): void {
                foreach ($imports as $import) {
                    try {
                        $promoter->promote($import, $user);
                        $promoted++;
                    } catch (Throwable $exception) {
                        $failed++;
                        if ($firstError === null) {
                            $firstError = $exception->getMessage();
                        }
                    }
                }
            });

        return response()->json([
            'data' => [
                'promoted' => $promoted,
                'failed' => $failed,
                'firstError' => $firstError,
            ],
        ]);
    }
}
