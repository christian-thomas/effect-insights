<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            // Per-org retention period for the consent event archive.
            // NULL = retain indefinitely (default — what the platform did
            // before this knob existed). Any positive integer triggers
            // the consent-events:purge command to delete rows older than
            // N days during its nightly run.
            $table->unsignedSmallInteger('consent_retention_days')
                ->nullable()
                ->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn('consent_retention_days');
        });
    }
};
