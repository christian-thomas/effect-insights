<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('domain_id')->constrained()->cascadeOnDelete();
            // Denormalised so BelongsToOrganisation works directly without a
            // join; integrity-checked in BannerConfig::booted().
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->boolean('is_draft')->default(true);
            $table->boolean('is_published')->default(false);
            $table->string('layout');
            $table->string('locale_default', 8)->default('en');
            $table->json('locales_enabled');                  // ['en', 'fr', ...]
            $table->json('colors');
            $table->json('copy');                             // { en: {...}, fr: {...} }
            $table->json('gcm_defaults');                     // 7 GCM v2 signals
            $table->json('geo_rules');                        // { mode, countries? }
            $table->json('behaviour');                        // rePromptAfterDays etc
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['domain_id', 'is_draft']);
            $table->index(['domain_id', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_configs');
    }
};
