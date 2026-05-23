<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('domain_id')->constrained()->cascadeOnDelete();
            // Denormalised so BelongsToOrganisation scopes work directly.
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('banner_config_version');
            $table->string('anonymous_id');
            $table->json('categories');
            $table->json('gcm_signals');
            $table->string('country_code', 2)->nullable();
            $table->string('user_agent_hash');
            $table->string('ip_hash');
            $table->string('event_type'); // granted | denied | changed | withdrawn
            $table->timestamp('occurred_at');
            $table->string('banner_text_hash');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organisation_id', 'occurred_at']);
            $table->index(['domain_id', 'occurred_at']);
            $table->index('anonymous_id');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_events');
    }
};
