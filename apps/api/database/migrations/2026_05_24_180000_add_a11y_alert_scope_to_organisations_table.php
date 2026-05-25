<?php

declare(strict_types=1);

use App\Services\Notifications\AccessibilityAlertService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org scope for which axe-core rules count toward the alert
 * threshold:
 *
 *   - 'all'       — every finding counts. Default; preserves the
 *                   pre-feature behaviour for existing orgs.
 *   - 'wcag_only' — best-practice findings (axe tag 'best-practice'
 *                   with no wcag* tag) are dropped before threshold
 *                   evaluation. Useful when ops only wants alerts on
 *                   legally-required findings.
 *
 * Resolution at alert time:
 *   {@see AccessibilityAlertService::effectiveByImpact()}
 *   reads this column and filters before counting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->string('a11y_alert_scope', 16)
                ->default('all')
                ->after('a11y_alert_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn('a11y_alert_scope');
        });
    }
};
