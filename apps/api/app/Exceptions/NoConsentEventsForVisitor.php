<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\ConsentEvents\ConsentReceiptBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown by {@see ConsentReceiptBuilder}
 * when a visitor receipt is requested for an `anonymous_id` + domain
 * pair that has no consent events on record. Translates to 404 with
 * a stable, operator-facing message.
 */
final class NoConsentEventsForVisitor extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'no consent events found for the given anonymous_id + domain_id',
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage()],
            Response::HTTP_NOT_FOUND,
        );
    }
}
