<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CookieCategory;
use App\Models\Concerns\BelongsToOrganisation;
use App\Services\Cookies\CookieCategoryResolver;
use Database\Factories\CookieOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-organisation (and optionally per-domain) override of the canonical
 * cookie categorisation. Lets a client say "for our setup, treat _hjid as
 * `statistics` even though the canonical DB says `preferences`".
 *
 * Resolution precedence (see {@see CookieCategoryResolver}):
 *   1. Domain-scoped override (domain_id set)
 *   2. Organisation-wide override (domain_id NULL)
 *   3. Canonical cookies table
 */
#[Fillable([
    'organisation_id', 'domain_id', 'name_pattern', 'category',
    'purpose', 'justification', 'created_by_user_id',
])]
class CookieOverride extends Model
{
    /** @use HasFactory<CookieOverrideFactory> */
    use BelongsToOrganisation, HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'category' => CookieCategory::class,
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
