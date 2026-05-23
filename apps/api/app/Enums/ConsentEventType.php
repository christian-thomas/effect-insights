<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirror of cmp.js {@see \packages/types/src/consent.ts}'s ConsentEventType
 * — keep the `->value` strings in sync.
 */
enum ConsentEventType: string
{
    case Granted = 'granted';
    case Denied = 'denied';
    case Changed = 'changed';
    case Withdrawn = 'withdrawn';
}
