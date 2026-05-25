<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passkey credentials table. We use UUIDs throughout (project rule) so
 * we can't ship the spatie/laravel-passkeys stub migration as-is —
 * that one uses $table->id() and would diverge from every other
 * table.
 *
 * Columns mirror the upstream stub one-for-one so the package's
 * actions read the same shape; only the PK + FK types differ.
 *
 * data: JSON of the serialised WebAuthn credential (public key, sign
 * counter, transports, etc). Read back through the package's
 * Passkey::data accessor which deserialises it into a
 * PublicKeyCredentialSource value object.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('authenticatable_id')
                ->constrained('users', indexName: 'passkeys_authenticatable_fk')
                ->cascadeOnDelete();
            $table->text('name');
            $table->text('credential_id');
            $table->json('data');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
