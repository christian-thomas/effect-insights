<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Exceptions\InvalidReceiptFormat;

/**
 * The two output formats supported by consent-receipt downloads.
 * `Json` is the regulator-verifiable artefact (signed via HMAC and
 * round-trip checkable); `Pdf` is the human-readable variant rendered
 * via the same payload through dompdf.
 */
enum ReceiptFormat: string
{
    case Json = 'json';
    case Pdf = 'pdf';

    /**
     * Resolve a query-string value to the enum, defaulting to `Json`
     * when the operator didn't specify. Rejects anything that isn't a
     * recognised case with a typed exception so the global handler
     * surfaces a clean 422 with a stable message.
     */
    public static function fromRequestValue(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Json;
        }

        return self::tryFrom($value) ?? throw new InvalidReceiptFormat($value);
    }
}
