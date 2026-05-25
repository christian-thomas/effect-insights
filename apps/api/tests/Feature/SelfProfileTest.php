<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class SelfProfileTest extends TenantTestCase
{
    #[Test]
    public function patch_me_requires_auth(): void
    {
        $this->patchJson('/api/me', ['name' => 'Renamed'])
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function patch_me_updates_name_and_email(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->patchJson('/api/me', [
                'name' => 'Updated Name',
                'email' => 'updated@example.test',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated Name')
            ->assertJsonPath('user.email', 'updated@example.test');

        $this->assertDatabaseHas('users', [
            'id' => $this->user->getKey(),
            'name' => 'Updated Name',
            'email' => 'updated@example.test',
        ]);
    }

    #[Test]
    public function patch_me_rejects_email_in_use_by_another_user(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->patchJson('/api/me', [
                'email' => $this->otherUser->getAttribute('email'),
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function patch_me_allows_keeping_own_email(): void
    {
        $original = $this->user->getAttribute('email');

        $this->actingAsMember($this->user, $this->org)
            ->patchJson('/api/me', [
                'name' => 'Same Email',
                'email' => $original,
            ])
            ->assertOk();
    }

    #[Test]
    public function change_password_requires_current_password(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/me/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-strong-password',
                'password_confirmation' => 'new-strong-password',
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('current_password');
    }

    #[Test]
    public function change_password_requires_confirmation_match(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/me/password', [
                'current_password' => 'password',
                'password' => 'new-strong-password',
                'password_confirmation' => 'different-confirmation',
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('password');
    }

    #[Test]
    public function change_password_persists_a_fresh_hash(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/me/password', [
                'current_password' => 'password',
                'password' => 'new-strong-password',
                'password_confirmation' => 'new-strong-password',
            ])
            ->assertStatus(Response::HTTP_NO_CONTENT);

        /** @var User $fresh */
        $fresh = $this->user->fresh();
        $this->assertTrue(Hash::check('new-strong-password', (string) $fresh->getAuthPassword()));
        $this->assertFalse(Hash::check('password', (string) $fresh->getAuthPassword()));
    }
}
