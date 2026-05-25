<?php

declare(strict_types=1);

namespace App\Services\Cookies;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when the uploaded CSV doesn't match the Open-Cookie-Database
 * column layout (or our compatible subset). Caught by the controller and
 * surfaced as a 422 with a clean operator-facing message — never a 500
 * with a stack-traced "Undefined array key" toast.
 */
final class InvalidCookieImportFormat extends RuntimeException
{
    public const int STATUS = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * @param  list<string>  $missing
     */
    public static function missingColumns(array $missing): self
    {
        return new self(sprintf(
            "Your CSV is missing the required column(s): %s. The importer expects the Open-Cookie-Database column layout — download the example CSV from the import dialog for a working template.",
            implode(', ', $missing),
        ));
    }

    public static function headerUnreadable(): self
    {
        return new self(
            "The uploaded file doesn't look like a CSV — couldn't read a header row. Make sure you're uploading the .csv export, not a binary or zipped file.",
        );
    }
}
