<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organisation override of the accessibility alert impact threshold.
 *
 * NULL = use the global `services.accessibility.alert_threshold` env
 * default (which is `serious` unless overridden). Setting one of
 * `critical | serious | moderate | minor` overrides per-org so different
 * tenants can run different policies on the same platform.
 *
 * Reads in App\Services\Notifications\AccessibilityAlertService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->string('a11y_alert_threshold', 16)
                ->nullable()
                ->after('notification_email');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn('a11y_alert_threshold');
        });
    }
};
