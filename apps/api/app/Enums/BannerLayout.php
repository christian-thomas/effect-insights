<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirror of {@see \packages/types/src/banner.ts}'s BannerLayout union —
 * keep the `->value` strings in sync.
 */
enum BannerLayout: string
{
    case Modal = 'modal';
    case BarTop = 'bar-top';
    case BarBottom = 'bar-bottom';
    case Corner = 'corner';
}
