<?php

declare(strict_types=1);

namespace App\Enums;

enum DomainStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
}
