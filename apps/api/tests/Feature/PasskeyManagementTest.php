<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Covers the four authed passkey CRUD endpoints. The full WebAuthn
 * ceremony (browser-side credential creation, signature verification)
 * needs a real authenticator and lives in Playwright e2e — these
 * tests cover the API contract + tenant isolation + session plumbing.
 */
class PasskeyManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum stateful guard only engages with SPA origin headers.
        $this->withHeaders([
            'Origin' => 'http://localhost:3001',
            'Referer' => 'http://localhost:3001/',
        ]);

        $this->user = User::create([
            'name' => 'Member',
            'email' => 'member@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    #[Test]
    public function register_options_requires_auth(): void
    {
        $this->postJson('/api/passkeys/register-options')
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function register_options_stashes_the_challenge_in_the_session(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/passkeys/register-options')
            ->assertOk();

        // WebAuthn options shape — bare minimum sanity that the
        // package handed us the right structure.
        $this->assertNotEmpty($response->json('rp.id'));
        $this->assertNotEmpty($response->json('user.id'));
        $this->assertSame((string) session('passkey-register-options'), $response->getContent());
    }

    #[Test]
    public function list_returns_only_current_users_passkeys(): void
    {
        $other = User::create([
            'name' => 'Other',
            'email' => 'other@example.test',
            'password' => Hash::make('password'),
        ]);

        // Seed two passkeys belonging to the current user and one
        // belonging to someone else. Bypass the WebAuthn ceremony by
        // writing the rows directly with placeholder bytes.
        $this->seedPasskey($this->user, 'Macbook');
        $this->seedPasskey($this->user, 'YubiKey');
        $this->seedPasskey($other, 'Theirs');

        $response = $this->actingAs($this->user)
            ->getJson('/api/passkeys')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertCount(2, $names);
        $this->assertContains('Macbook', $names);
        $this->assertContains('YubiKey', $names);
        $this->assertNotContains('Theirs', $names);
    }

    #[Test]
    public function list_response_does_not_leak_credential_id_or_public_key_data(): void
    {
        $this->seedPasskey($this->user, 'Macbook');

        $response = $this->actingAs($this->user)
            ->getJson('/api/passkeys')
            ->assertOk();

        $first = $response->json('data.0');
        $this->assertArrayNotHasKey('data', $first);
        $this->assertArrayNotHasKey('credential_id', $first);
        $this->assertArrayNotHasKey('credentialId', $first);
    }

    #[Test]
    public function delete_removes_the_users_own_passkey(): void
    {
        $passkey = $this->seedPasskey($this->user, 'Macbook');

        $this->actingAs($this->user)
            ->deleteJson(sprintf('/api/passkeys/%s', $passkey->getKey()))
            ->assertStatus(Response::HTTP_NO_CONTENT);

        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->getKey()]);
    }

    #[Test]
    public function delete_404s_for_passkeys_belonging_to_other_users(): void
    {
        $other = User::create([
            'name' => 'Other',
            'email' => 'other@example.test',
            'password' => Hash::make('password'),
        ]);
        $foreign = $this->seedPasskey($other, 'Theirs');

        $this->actingAs($this->user)
            ->deleteJson(sprintf('/api/passkeys/%s', $foreign->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);

        $this->assertDatabaseHas('passkeys', ['id' => $foreign->getKey()]);
    }

    #[Test]
    public function store_returns_400_without_a_session_challenge(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/passkeys', [
                'passkey' => '{"id":"x","type":"public-key"}',
                'name' => 'Macbook',
            ])
            ->assertStatus(Response::HTTP_BAD_REQUEST);
    }

    #[Test]
    public function authentication_options_does_not_require_auth(): void
    {
        $response = $this->postJson('/api/passkeys/authentication-options')
            ->assertOk();

        $this->assertNotEmpty($response->json('rpId'));
        $this->assertNotEmpty($response->json('challenge'));
    }

    /**
     * Direct DB insertion to dodge the spatie StorePasskeyAction's
     * WebAuthn signature check — those need a real authenticator.
     * Stores arbitrary bytes in the `data` column; nothing in the
     * passkey-management endpoints reads the value, only the auth
     * endpoint does (covered by Playwright e2e).
     */
    private function seedPasskey(User $owner, string $name): Passkey
    {
        $passkey = new Passkey;
        $passkey->setAttribute('authenticatable_id', $owner->getKey());
        $passkey->setAttribute('name', $name);
        $passkey->setAttribute('credential_id', 'placeholder_'.bin2hex(random_bytes(8)));
        $passkey->setRawAttributes(
            array_merge($passkey->getAttributes(), ['data' => '{}']),
            sync: false,
        );
        $passkey->save();

        return $passkey;
    }
}
