<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CookieCategory;
use App\Enums\CookieSource;
use Database\Factories\CookieFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Canonical pattern-based cookie database. Each row maps a name pattern
 * (`_ga_*`, `_fbp`, …) to a category + purpose + vendor metadata.
 *
 * Rows are append-only — superseding categorisations create a new row
 * and point `supersedes_id` at the previous one, so existing
 * `scan_findings` referencing the old row still resolve.
 */
#[Fillable([
    'name_pattern', 'vendor', 'vendor_domain', 'category', 'purpose',
    'expiry_hint', 'source', 'source_version', 'supersedes_id',
])]
class Cookie extends Model
{
    /** @use HasFactory<CookieFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'category' => CookieCategory::class,
            'source' => CookieSource::class,
        ];
    }
}
