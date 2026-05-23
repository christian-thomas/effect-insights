<?php

declare(strict_types=1);

namespace App\Enums;

enum CookieResolutionSource: string
{
    case Canonical = 'canonical';
    case OrganisationOverride = 'organisation_override';
    case DomainOverride = 'domain_override';
}
