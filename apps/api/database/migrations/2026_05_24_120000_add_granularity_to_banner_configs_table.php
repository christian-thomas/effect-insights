<?php

declare(strict_types=1);

use App\Enums\BannerGranularity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banner_configs', function (Blueprint $table): void {
            // Defaults to `categorised` so every existing row matches the
            // wire-format payload the builder already emitted statically.
            $table->string('granularity', 16)
                ->default(BannerGranularity::Categorised->value)
                ->after('layout');
        });
    }

    public function down(): void
    {
        Schema::table('banner_configs', function (Blueprint $table): void {
            $table->dropColumn('granularity');
        });
    }
};
