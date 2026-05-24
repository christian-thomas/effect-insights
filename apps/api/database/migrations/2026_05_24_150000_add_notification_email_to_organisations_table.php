<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a per-org notification email. NULL = no email channel (Slack
 * only). Set to a string and all four alert services (UnknownCookie,
 * ScanFailed, AccessibilityIssuesFound, AccessibilityScanFailed) will
 * also fire a mail notification to that address alongside Slack.
 *
 * Multiple recipients: store comma-separated, the alert service splits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->string('notification_email', 320)
                ->nullable()
                ->after('consent_retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn('notification_email');
        });
    }
};
