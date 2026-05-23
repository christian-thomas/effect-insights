<?php

declare(strict_types=1);

namespace App\Services\ConsentArchive;

final readonly class ArchiveResult
{
    public function __construct(
        public int $upserted,
        public int $deleted,
        public int $skipped,
        public string $cutoff,
    ) {}
}
