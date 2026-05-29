<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The scanner worker reports the URL of a script/iframe/network-request
 * as the finding's `name` — these can run 500+ chars for tracking URLs
 * with long query strings, blowing past the varchar(255) limit and
 * triggering "Data too long for column 'name'" failures on the per-URL
 * jobs.
 *
 * Fix: widen `name` to TEXT, and shift the dedupe-uniqueness off `name`
 * (which can't participate in a multi-column index past MySQL's 3072-byte
 * key length cap) onto a new `name_hash` column (SHA-256 hex, 64 chars).
 * The application computes the hash on save — see {@see \App\Models\ScanFinding}.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('scan_findings', function (Blueprint $table): void {
            $table->dropUnique(['scan_id', 'type', 'name']);
        });

        if ($driver === 'mysql') {
            // Drop the single-column name index — MySQL won't keep a
            // non-prefix index on a TEXT column.
            DB::statement('ALTER TABLE scan_findings DROP INDEX scan_findings_name_index');
            DB::statement('ALTER TABLE scan_findings MODIFY COLUMN name TEXT NOT NULL');
        } else {
            // SQLite (tests): TEXT is the same as varchar in storage and
            // has no key length cap, so the change is mostly cosmetic.
            Schema::table('scan_findings', function (Blueprint $table): void {
                $table->dropIndex(['name']);
                $table->text('name')->change();
            });
        }

        Schema::table('scan_findings', function (Blueprint $table): void {
            $table->char('name_hash', 64)->after('name');
            $table->unique(['scan_id', 'type', 'name_hash']);
        });

        if ($driver === 'mysql') {
            // Prefix index lets us still scan/filter findings by name —
            // 255 chars is enough for cookie/script lookups in admin.
            DB::statement('ALTER TABLE scan_findings ADD INDEX scan_findings_name_prefix_index (name(255))');
        } else {
            Schema::table('scan_findings', function (Blueprint $table): void {
                $table->index('name');
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE scan_findings DROP INDEX scan_findings_name_prefix_index');
        } else {
            Schema::table('scan_findings', function (Blueprint $table): void {
                $table->dropIndex(['name']);
            });
        }

        Schema::table('scan_findings', function (Blueprint $table): void {
            $table->dropUnique(['scan_id', 'type', 'name_hash']);
            $table->dropColumn('name_hash');
        });

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE scan_findings MODIFY COLUMN name VARCHAR(255) NOT NULL');
            DB::statement('ALTER TABLE scan_findings ADD INDEX scan_findings_name_index (name)');
        } else {
            Schema::table('scan_findings', function (Blueprint $table): void {
                $table->string('name')->change();
                $table->index('name');
            });
        }

        Schema::table('scan_findings', function (Blueprint $table): void {
            $table->unique(['scan_id', 'type', 'name']);
        });
    }
};
