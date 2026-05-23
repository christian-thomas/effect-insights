<?php

declare(strict_types=1);

namespace App\Services\Cookies;

use App\Enums\CookieImportStatus;
use App\Models\Cookie;
use App\Models\CookieImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves a pending {@see CookieImport} into the canonical {@see Cookie}
 * table. Marks the staging row `promoted` and back-references the new
 * canonical row via `promoted_cookie_id` for the audit trail.
 *
 * If a canonical row already exists for the same (source, source_version,
 * name_pattern), the staging row is still flipped to `promoted` and
 * pointed at the existing canonical row — re-promoting is idempotent.
 */
final class CookieImportPromoter
{
    public function promote(CookieImport $import, ?User $reviewer = null): Cookie
    {
        return DB::transaction(function () use ($import, $reviewer): Cookie {
            $cookie = Cookie::firstOrCreate(
                [
                    'source' => $import->getAttribute('source'),
                    'source_version' => $import->getAttribute('source_version'),
                    'name_pattern' => $import->getAttribute('name_pattern'),
                ],
                [
                    'vendor' => $import->getAttribute('vendor'),
                    'vendor_domain' => $import->getAttribute('vendor_domain'),
                    'category' => $import->getAttribute('category'),
                    'purpose' => $import->getAttribute('purpose'),
                    'expiry_hint' => $import->getAttribute('expiry_hint'),
                ],
            );

            $import->update([
                'status' => CookieImportStatus::Promoted,
                'reviewed_by_user_id' => $reviewer?->getKey(),
                'reviewed_at' => now(),
                'promoted_cookie_id' => $cookie->getKey(),
            ]);

            return $cookie;
        });
    }

    public function reject(CookieImport $import, ?User $reviewer = null): CookieImport
    {
        $import->update([
            'status' => CookieImportStatus::Rejected,
            'reviewed_by_user_id' => $reviewer?->getKey(),
            'reviewed_at' => now(),
        ]);

        return $import->refresh();
    }
}
