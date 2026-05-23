<?php

declare(strict_types=1);

namespace App\Services\Cookies;

final readonly class ImportResult
{
    public function __construct(
        public int $created,
        public int $existing,
        public int $skipped,
    ) {}
}
