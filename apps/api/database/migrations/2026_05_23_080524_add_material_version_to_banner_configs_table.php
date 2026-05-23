<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banner_configs', function (Blueprint $table): void {
            // Separate counter from `version` — increments only when a publish
            // is materially different from the previous one (GCM defaults,
            // geo rules, GPC/auto-deny/cross-subdomain behaviour, locale set).
            // Drives the cmp.js re-prompt decision: visitors whose stored
            // consent material_version lags the current one see the banner again.
            $table->unsignedInteger('material_version')->default(0)->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('banner_configs', function (Blueprint $table): void {
            $table->dropColumn('material_version');
        });
    }
};
