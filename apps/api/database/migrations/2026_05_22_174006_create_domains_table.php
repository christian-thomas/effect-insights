<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('fqdn');
            $table->string('apex_domain');
            $table->string('status')->default('pending');
            $table->string('public_key', 64)->unique();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'fqdn']);
            $table->index('apex_domain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
