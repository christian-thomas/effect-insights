<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cookies', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Pattern-based — `_ga_*` covers every property-scoped GA4 cookie
            // (`_ga_ABC123`, `_ga_XYZ789`, …) without enumerating them.
            // Supports `*` as a wildcard; everything else is treated literally.
            $table->string('name_pattern', 191);

            $table->string('vendor');
            $table->string('vendor_domain')->nullable();

            // Mirrors the four categories cmp.js renders. Stored as a string
            // (not a DB enum) so adding categories later doesn't require an
            // ALTER on every environment.
            $table->string('category');
            $table->text('purpose');
            $table->string('expiry_hint')->nullable();

            // Where this row came from. `manual` = seeded by us; `opencookiedb`
            // = imported. source_version pins the OCD release for traceability.
            $table->string('source')->default('manual');
            $table->string('source_version')->nullable();

            // Append-only — when a category/purpose changes we insert a new
            // row and point supersedes_id at the old one, so existing
            // scan_findings referencing the old row still resolve.
            $table->foreignUuid('supersedes_id')->nullable()->constrained('cookies')->nullOnDelete();

            $table->timestamps();

            $table->unique(['name_pattern', 'source', 'source_version'], 'cookies_pattern_source_unique');
            $table->index('name_pattern');
            $table->index('category');
            $table->index('vendor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cookies');
    }
};
