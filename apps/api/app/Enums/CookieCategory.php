<?php

declare(strict_types=1);

namespace App\Enums;

enum CookieCategory: string
{
    case Necessary = 'necessary';
    case Preferences = 'preferences';
    case Statistics = 'statistics';
    case Marketing = 'marketing';
}
