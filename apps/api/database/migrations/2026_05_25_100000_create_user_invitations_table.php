<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('organisation_id')
                ->constrained('organisations')
                ->cascadeOnDelete();

            $table->string('email');
            // Pivot role on `organisation_user` — owner / admin / viewer.
            $table->string('role');
            // Cryptographically random hex (64 chars). Sent in the magic-
            // link URL. Indexed unique so /api/invitations/{token} is O(1).
            $table->string('token')->unique();
            $table->timestamp('expires_at');

            $table->foreignUuid('invited_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['organisation_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
