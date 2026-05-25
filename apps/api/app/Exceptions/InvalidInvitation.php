<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when an invitation token is missing, expired, or already
 * consumed. Translates to 404 with a single stable operator-facing
 * message — we deliberately collapse the three cases into one so
 * we don't leak the existence of valid-but-expired tokens.
 */
final class InvalidInvitation extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This invitation is invalid, expired, or already used.');
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => 'invitation_invalid',
                'message' => $this->getMessage(),
            ],
            Response::HTTP_NOT_FOUND,
        );
    }
}
