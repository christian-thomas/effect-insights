<?php

declare(strict_types=1);

use App\Enums\ScanCadence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-domain accessibility-scan cadence. Reuses the existing
 * ScanCadence enum (Weekly / Monthly / Never).
 *
 * Default is `never` — accessibility scans are opt-in so existing
 * CMP-only domains don't surprise the operator with unexpected
 * Browser Rendering spend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->string('a11y_scan_cadence')
                ->default(ScanCadence::Never->value)
                ->after('scan_cadence');
            $table->timestamp('last_a11y_scanned_at')
                ->nullable()
                ->after('last_scanned_at');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn(['a11y_scan_cadence', 'last_a11y_scanned_at']);
        });
    }
};
