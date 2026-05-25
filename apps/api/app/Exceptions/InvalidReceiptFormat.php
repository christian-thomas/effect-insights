<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Responses\ReceiptFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown by {@see ReceiptFormat::fromRequestValue}
 * when the operator requested a download format other than `json` or
 * `pdf`. Translates to 422 with a stable, operator-facing message so
 * controllers don't need an inline `if (!in_array(...)) return …`.
 */
final class InvalidReceiptFormat extends RuntimeException
{
    public function __construct(string $given)
    {
        parent::__construct(sprintf(
            'format must be json or pdf, got "%s"',
            $given,
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(
            ['message' => 'format must be json or pdf'],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
