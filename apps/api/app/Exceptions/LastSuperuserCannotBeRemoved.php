<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-removal safety net — if the acting superuser is the only
 * remaining superuser platform-wide, removing them would lock the
 * platform out. Thrown by the RemoveMember action so the controller
 * stays a one-liner.
 */
final class LastSuperuserCannotBeRemoved extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            "You're the last superuser — assign that role to another user before removing yourself.",
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
