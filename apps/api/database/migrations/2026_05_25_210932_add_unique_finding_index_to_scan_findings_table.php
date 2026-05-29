<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a unique index on (scan_id, type, name) so the per-URL scan jobs
 * dispatched by ScanRunner can call `firstOrCreate` to dedupe findings
 * observed on multiple pages of the same scan.
 *
 * Sits alongside the existing non-unique [scan_id, type] index rather
 * than replacing it — MySQL refuses to drop the latter while the
 * scan_id foreign key relies on it as a covering index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_findings', function (Blueprint $table): void {
            $table->unique(['scan_id', 'type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('scan_findings', function (Blueprint $table): void {
            $table->dropUnique(['scan_id', 'type', 'name']);
        });
    }
};
