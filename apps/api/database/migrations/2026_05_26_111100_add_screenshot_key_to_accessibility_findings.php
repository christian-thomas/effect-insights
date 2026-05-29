<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the disk-relative storage key for element screenshots captured by
 * the accessibility scanner. The bytes live on the `a11y_screenshots`
 * filesystem disk (local in dev, R2 in prod); this column just points
 * at them. Nullable because elements that can't be resolved or rendered
 * (display:none, off-viewport, etc.) leave the finding without an image.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessibility_findings', function (Blueprint $table): void {
            $table->string('screenshot_key', 512)->nullable()->after('nodes');
        });
    }

    public function down(): void
    {
        Schema::table('accessibility_findings', function (Blueprint $table): void {
            $table->dropColumn('screenshot_key');
        });
    }
};
