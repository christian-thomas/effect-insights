<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sibling to `scans`. Holds the lifecycle state for a single axe-core
 * crawl across a domain's discovered URLs. Individual rule violations
 * live in {@see accessibility_findings}. Deliberately separate from
 * the cookie `scans` table so that table's enum/queue assumptions
 * stay specific to the cookie pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessibility_scans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();

            $table->string('status'); // queued | running | completed | failed (App\Enums\ScanStatus)
            $table->string('trigger'); // manual | scheduled (App\Enums\ScanTrigger)

            $table->unsignedInteger('urls_discovered')->default(0);
            $table->unsignedInteger('urls_scanned')->default(0);

            // Aggregate counters — keeps the admin index page fast.
            $table->unsignedInteger('violations_total')->default(0);
            // { critical: N, serious: N, moderate: N, minor: N }
            $table->json('violations_by_impact')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('error_log')->nullable();
            $table->timestamps();

            $table->index(['domain_id', 'status']);
            $table->index(['organisation_id', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessibility_scans');
    }
};
