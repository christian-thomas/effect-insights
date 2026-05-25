<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-removal safety net — if the acting user is the only remaining
 * member of an organisation, removing them would orphan the org.
 * Thrown by the RemoveMember action so the controller stays a
 * one-liner.
 */
final class LastMemberCannotBeRemoved extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            "You're the last member of this organisation — invite someone else before removing yourself.",
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage()],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
