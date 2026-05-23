<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CookieCategory;
use App\Enums\CookieImportStatus;
use App\Enums\CookieSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staging row for an imported cookie entry awaiting human review. Once
 * promoted, `promoted_cookie_id` points at the row written into `cookies`;
 * the staging row is kept for audit (status flips to `promoted`).
 */
#[Fillable([
    'name_pattern', 'vendor', 'vendor_domain', 'category', 'purpose',
    'expiry_hint', 'source', 'source_version', 'status',
    'reviewed_by_user_id', 'reviewed_at', 'promoted_cookie_id',
])]
class CookieImport extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'category' => CookieCategory::class,
            'source' => CookieSource::class,
            'status' => CookieImportStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Cookie, $this>
     */
    public function promotedCookie(): BelongsTo
    {
        return $this->belongsTo(Cookie::class, 'promoted_cookie_id');
    }
}
