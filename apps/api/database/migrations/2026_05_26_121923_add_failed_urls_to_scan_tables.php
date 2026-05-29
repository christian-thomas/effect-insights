<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the URLs that failed during a chunked scan — either reported by
 * the worker (per-URL navigation errors that the worker now isolates and
 * collects rather than 500ing the whole chunk) or appended by the chunk
 * job's `failed()` callback when all retry attempts exhausted.
 *
 * Powers the new `Partial` ScanStatus + the "Retry failed URLs" admin
 * action that dispatches a fresh Bus::batch over just these URLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table): void {
            $table->json('failed_urls')->nullable()->after('error_log');
        });

        Schema::table('accessibility_scans', function (Blueprint $table): void {
            $table->json('failed_urls')->nullable()->after('error_log');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table): void {
            $table->dropColumn('failed_urls');
        });

        Schema::table('accessibility_scans', function (Blueprint $table): void {
            $table->dropColumn('failed_urls');
        });
    }
};
