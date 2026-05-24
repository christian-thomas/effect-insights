<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-org (and optionally per-domain) mute for a specific axe-core rule.
 *
 * Sibling of {@see CookieOverride}. When a row exists matching a
 * finding's rule_id, the alert service skips that finding when
 * computing the alert-threshold count, and the admin scan-detail
 * page marks it as muted in the UI.
 */
#[Fillable([
    'organisation_id', 'domain_id', 'rule_id', 'reason', 'created_by_user_id',
])]
class AccessibilityRuleOverride extends Model
{
    use BelongsToOrganisation, HasUuids;

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
