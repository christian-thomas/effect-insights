<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Defensive widening of `accessibility_findings.url` to TEXT. Page URLs
 * are usually well under varchar(255), but heavy SPAs can produce
 * tracking-flavoured URLs that overflow — same failure mode as the
 * cookie scanner's `name` column had against tracking-pixel URLs.
 *
 * No unique index touches this column so we don't need the
 * hash-trick the cookie scanner uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE accessibility_findings MODIFY COLUMN url TEXT NOT NULL');
        } else {
            Schema::table('accessibility_findings', function (Blueprint $table): void {
                $table->text('url')->change();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE accessibility_findings MODIFY COLUMN url VARCHAR(255) NOT NULL');
        } else {
            Schema::table('accessibility_findings', function (Blueprint $table): void {
                $table->string('url')->change();
            });
        }
    }
};
