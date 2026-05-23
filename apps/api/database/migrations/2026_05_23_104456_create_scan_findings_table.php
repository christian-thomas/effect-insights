<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('scan_id')->constrained()->cascadeOnDelete();
            // Denormalised from scan->domain->organisation_id. Lets
            // BelongsToOrganisation scope findings without a triple join.
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();

            // What this finding represents. cookie | script | iframe | network_request.
            $table->string('type');
            $table->string('name');
            $table->string('domain_set_on')->nullable();
            $table->text('value_pattern')->nullable();

            // first = same registrable domain as the page, third = anywhere else.
            $table->string('party');

            // Resolved category from CookieCategoryResolver. NULL when no
            // canonical or override matched — these surface in the manual
            // review queue.
            $table->string('category')->nullable();
            $table->string('category_source')->nullable();
            $table->text('purpose_text')->nullable();

            $table->string('first_seen_url')->nullable();

            // Back-references to the rows that matched. Either can be set
            // depending on category_source; both NULL when category is NULL.
            $table->foreignUuid('cookie_id')->nullable()->constrained('cookies')->nullOnDelete();
            $table->foreignUuid('cookie_override_id')->nullable()->constrained('cookie_overrides')->nullOnDelete();

            // Anything the scanner returned that we don't surface as a first-
            // class column. Useful for debugging mis-categorisation.
            $table->json('raw_data')->nullable();

            $table->timestamps();

            $table->index(['scan_id', 'type']);
            $table->index(['organisation_id', 'category']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_findings');
    }
};
