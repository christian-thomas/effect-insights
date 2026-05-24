<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org / per-domain mute list for accessibility rule IDs.
 *
 * Sibling of `cookie_overrides`. Each row mutes a specific axe-core
 * rule (`color-contrast`, `image-alt`, …) so it stops contributing to
 * alert-threshold counts. Findings still land in
 * `accessibility_findings` (the audit trail stays intact) but get
 * marked `muted: true` in the API response.
 *
 * Resolution precedence:
 *   1. domain-scoped override (organisation_id + domain_id match)
 *   2. organisation-wide override (organisation_id, domain_id NULL)
 *
 * Either suppresses the rule. No "ignore globally" — every override
 * is scoped to a tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessibility_rule_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('domain_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('rule_id');
            // Why this rule was muted. Audit / changelog material —
            // forces operators to leave a justification on the way in.
            $table->text('reason')->nullable();

            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A given rule can be muted once org-wide and once per-domain.
            // Composite uniqueness across (org, domain, rule); NULL domain
            // counts as a distinct value in MySQL/SQLite uniqueness rules
            // so this enforces "no duplicates at the same scope".
            $table->unique(['organisation_id', 'domain_id', 'rule_id'], 'a11y_rule_overrides_scope_unique');
            $table->index(['organisation_id', 'rule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessibility_rule_overrides');
    }
};
