<?php

declare(strict_types=1);

namespace App\Enums;

enum CookieImportStatus: string
{
    case Pending = 'pending';
    case Promoted = 'promoted';
    case Rejected = 'rejected';
}
