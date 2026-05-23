<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('domain_id')->constrained()->cascadeOnDelete();
            // Denormalised so BelongsToOrganisation works without joining the
            // domain. Always sourced from $domain->organisation_id in the
            // model's creating hook.
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();

            $table->string('status')->default('queued');
            $table->string('trigger');

            $table->unsignedInteger('urls_discovered')->default(0);
            $table->unsignedInteger('urls_scanned')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Free-form error context — stack traces, partial-success details,
            // anything the operator needs when triaging a failed scan.
            $table->json('error_log')->nullable();

            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['domain_id', 'status']);
            $table->index(['organisation_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
