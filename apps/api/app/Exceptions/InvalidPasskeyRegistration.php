<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Actions\Passkeys\RegisterPasskey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Thrown by {@see RegisterPasskey} when the
 * WebAuthn ceremony fails — either because the session-stashed
 * challenge is missing (replay protection) or spatie's verification
 * rejected the credential.
 *
 * Translates to 400 when there's no challenge in session (the SPA
 * forgot to call /register-options first), 422 otherwise.
 */
final class InvalidPasskeyRegistration extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function missingChallenge(): self
    {
        return new self(
            'No registration options found in session — request a fresh challenge.',
            Response::HTTP_BAD_REQUEST,
        );
    }

    public static function fromSpatie(Throwable $previous): self
    {
        return new self($previous->getMessage(), previous: $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
