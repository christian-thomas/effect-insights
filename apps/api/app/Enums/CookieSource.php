<?php

declare(strict_types=1);

namespace App\Enums;

enum CookieSource: string
{
    case Manual = 'manual';
    case OpenCookieDatabase = 'opencookiedb';
}
