<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per axe-core violation observed during a scan. We don't dedup
 * by `(rule_id, url)` at write time — the same rule can fire on multiple
 * pages with different node targets, and the admin filters/groups in
 * the UI. The `nodes` JSON column carries the per-element details
 * (CSS target selector + HTML snippet + axe failureSummary).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessibility_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('accessibility_scan_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();

            $table->string('rule_id'); // e.g. "color-contrast"
            $table->string('impact'); // App\Enums\A11yImpact
            $table->text('description');
            $table->text('help_text');
            $table->string('help_url');
            $table->string('url'); // page where the rule failed
            $table->json('tags')->nullable(); // ["wcag2aa","wcag143", ...]
            $table->json('nodes'); // [{ target: ["#foo"], html: "<p>...</p>", failureSummary: "..." }, ...]
            $table->timestamps();

            $table->index(['accessibility_scan_id', 'impact']);
            $table->index(['organisation_id', 'rule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessibility_findings');
    }
};
