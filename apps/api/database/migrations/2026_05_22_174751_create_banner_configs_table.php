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
            $table->json('colors');
            $table->json('copy');
            $table->string('layout');
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
