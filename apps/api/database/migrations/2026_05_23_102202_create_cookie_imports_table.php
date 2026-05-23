<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cookie_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Same shape as `cookies` — staging area for entries fetched from
            // OpenCookieDatabase (or similar). Rows here are reviewed by a
            // human and promoted into `cookies` via an admin action; until
            // promoted they have no effect on scan findings.
            $table->string('name_pattern', 191);
            $table->string('vendor');
            $table->string('vendor_domain')->nullable();
            $table->string('category');
            $table->text('purpose');
            $table->string('expiry_hint')->nullable();

            $table->string('source');
            $table->string('source_version');

            // `pending` until reviewed; then `promoted` or `rejected`.
            // Kept after promotion as an audit trail.
            $table->string('status')->default('pending');
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUuid('promoted_cookie_id')->nullable()->constrained('cookies')->nullOnDelete();

            $table->timestamps();

            // The unique key spans source + version + pattern so re-importing
            // the same OCD release is idempotent. A newer release with the
            // same pattern creates a new staging row to review.
            $table->unique(
                ['source', 'source_version', 'name_pattern'],
                'cookie_imports_source_pattern_unique',
            );
            $table->index('status');
            $table->index('name_pattern');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cookie_imports');
    }
};
