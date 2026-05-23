<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Per-organisation pivot role (stored on `organisation_user.role`). Distinct
 * from spatie/laravel-permission's global roles (e.g. superuser).
 */
enum OrganisationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Viewer = 'viewer';
}
