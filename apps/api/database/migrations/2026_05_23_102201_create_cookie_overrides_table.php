<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cookie_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Tenant-scoped. domain_id is nullable: NULL = org-wide override,
            // set = scoped to a single domain. Domain-specific overrides win
            // over org-wide ones (resolved in CookieCategoryResolver).
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('domain_id')->nullable()->constrained()->cascadeOnDelete();

            // Same pattern syntax as cookies.name_pattern.
            $table->string('name_pattern', 191);

            $table->string('category');
            $table->text('purpose')->nullable();
            $table->text('justification')->nullable();

            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One override per pattern per scope. domain_id=NULL means org-wide;
            // a domain-scoped override doesn't conflict with the org-wide one.
            $table->unique(
                ['organisation_id', 'domain_id', 'name_pattern'],
                'cookie_overrides_scope_pattern_unique',
            );
            $table->index(['organisation_id', 'domain_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cookie_overrides');
    }
};
