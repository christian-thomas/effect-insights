<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum's EnsureFrontendRequestsAreStateful middleware only attaches
        // the session when the request looks like it's coming from a stateful
        // SPA — mimic that by sending the Origin header on every test request.
        $this->withHeaders([
            'Origin' => 'http://localhost:3001',
            'Referer' => 'http://localhost:3001/login',
        ]);

        Role::firstOrCreate(['name' => User::ROLE_SUPERUSER, 'guard_name' => 'web']);

        $this->organisation = Organisation::create([
            'name' => 'Effect Digital',
            'slug' => 'effect-digital',
        ]);

        $this->user = User::create([
            'name' => 'Christian Thomas',
            'email' => 'me@christianthomas.co.uk',
            'password' => Hash::make('password'),
        ]);
        $this->user->assignRole(User::ROLE_SUPERUSER);
        $this->organisation->users()->attach($this->user, ['role' => OrganisationRole::Owner]);
    }

    #[Test]
    public function login_returns_the_user_and_organisations_on_valid_credentials(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'me@christianthomas.co.uk',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'isSuperuser'],
                'organisations' => [['id', 'name', 'slug']],
            ])
            ->assertJsonPath('user.email', 'me@christianthomas.co.uk')
            ->assertJsonPath('user.isSuperuser', true)
            ->assertJsonPath('organisations.0.slug', 'effect-digital');
    }

    #[Test]
    public function login_rejects_invalid_credentials(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'me@christianthomas.co.uk',
            'password' => 'wrong',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Test]
    public function login_validates_required_fields(): void
    {
        $response = $this->postJson('/api/login', []);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    #[Test]
    public function me_returns_the_authenticated_user(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('user.email', 'me@christianthomas.co.uk')
            ->assertJsonPath('organisations.0.name', 'Effect Digital');
    }

    #[Test]
    public function me_returns_unauthorised_when_no_session(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function logout_clears_the_session(): void
    {
        // Real login flow so logout has a session to invalidate.
        $this->postJson('/api/login', [
            'email' => 'me@christianthomas.co.uk',
            'password' => 'password',
        ])->assertOk();

        $response = $this->postJson('/api/logout');

        $response->assertOk()
            ->assertJsonPath('ok', true);
    }
}
