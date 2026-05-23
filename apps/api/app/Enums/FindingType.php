<?php

declare(strict_types=1);

namespace App\Enums;

enum FindingType: string
{
    case Cookie = 'cookie';
    case Script = 'script';
    case Iframe = 'iframe';
    case NetworkRequest = 'network_request';
}
