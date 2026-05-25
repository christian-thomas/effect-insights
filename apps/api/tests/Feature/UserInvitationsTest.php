<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrganisationRole;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvited;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class UserInvitationsTest extends TenantTestCase
{
    #[Test]
    public function create_requires_superuser(): void
    {
        Notification::fake();

        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/organisations/%s/invitations', $this->org->getKey()), [
                'email' => 'newperson@example.test',
                'role' => OrganisationRole::Viewer->value,
            ])
            ->assertStatus(Response::HTTP_FORBIDDEN);

        Notification::assertNothingSent();
    }

    #[Test]
    public function superuser_can_create_invitation_and_email_is_sent(): void
    {
        Notification::fake();
        $this->makeSuperuser($this->user);

        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/organisations/%s/invitations', $this->org->getKey()), [
                'email' => 'newperson@example.test',
                'role' => OrganisationRole::Viewer->value,
            ])
            ->assertStatus(Response::HTTP_CREATED)
            ->assertJsonPath('data.email', 'newperson@example.test')
            ->assertJsonPath('data.role', 'viewer');

        $this->assertDatabaseHas('user_invitations', [
            'organisation_id' => $this->org->getKey(),
            'email' => 'newperson@example.test',
            'role' => 'viewer',
        ]);

        Notification::assertSentOnDemand(UserInvited::class);
    }

    #[Test]
    public function create_rejects_email_already_a_member(): void
    {
        Notification::fake();
        $this->makeSuperuser($this->user);

        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/organisations/%s/invitations', $this->org->getKey()), [
                'email' => $this->user->getAttribute('email'),
                'role' => OrganisationRole::Viewer->value,
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('email');

        Notification::assertNothingSent();
    }

    #[Test]
    public function create_rejects_a_second_pending_invitation_for_the_same_email(): void
    {
        Notification::fake();
        $this->makeSuperuser($this->user);

        UserInvitation::create([
            'organisation_id' => $this->org->getKey(),
            'email' => 'newperson@example.test',
            'role' => OrganisationRole::Viewer,
            'token' => UserInvitation::generateToken(),
            'expires_at' => CarbonImmutable::now()->addDays(3),
            'invited_by_user_id' => $this->user->getKey(),
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->postJson(sprintf('/api/organisations/%s/invitations', $this->org->getKey()), [
                'email' => 'newperson@example.test',
                'role' => OrganisationRole::Viewer->value,
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function list_returns_only_pending_invitations_for_that_org(): void
    {
        $this->makeSuperuser($this->user);

        $pending = $this->seedInvitation($this->org->getKey(), 'pending@example.test');
        $expired = $this->seedInvitation($this->org->getKey(), 'expired@example.test');
        $expired->update(['expires_at' => CarbonImmutable::now()->subDay()]);
        $accepted = $this->seedInvitation($this->org->getKey(), 'accepted@example.test');
        $accepted->update(['accepted_at' => CarbonImmutable::now()]);
        $this->seedInvitation($this->otherOrg->getKey(), 'othertenant@example.test');

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/organisations/%s/invitations', $this->org->getKey()))
            ->assertOk();

        $emails = collect($response->json('data'))->pluck('email')->all();
        $this->assertSame(['pending@example.test'], $emails);
        $this->assertSame($pending->getKey(), $response->json('data.0.id'));
    }

    #[Test]
    public function revoke_deletes_the_invitation(): void
    {
        $this->makeSuperuser($this->user);
        $invitation = $this->seedInvitation($this->org->getKey(), 'newperson@example.test');

        $this->actingAsMember($this->user, $this->org)
            ->deleteJson(sprintf('/api/organisations/%s/invitations/%s', $this->org->getKey(), $invitation->getKey()))
            ->assertStatus(Response::HTTP_NO_CONTENT);

        $this->assertDatabaseMissing('user_invitations', ['id' => $invitation->getKey()]);
    }

    #[Test]
    public function revoke_404s_when_invitation_belongs_to_a_different_org(): void
    {
        $this->makeSuperuser($this->user);
        $foreign = $this->seedInvitation($this->otherOrg->getKey(), 'foreign@example.test');

        $this->actingAsMember($this->user, $this->org)
            ->deleteJson(sprintf('/api/organisations/%s/invitations/%s', $this->org->getKey(), $foreign->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);

        $this->assertDatabaseHas('user_invitations', ['id' => $foreign->getKey()]);
    }

    #[Test]
    public function show_invitation_returns_preview_for_valid_token(): void
    {
        $invitation = $this->seedInvitation($this->org->getKey(), 'newperson@example.test');

        $this->getJson(sprintf('/api/invitations/%s', $invitation->getAttribute('token')))
            ->assertOk()
            ->assertJsonPath('email', 'newperson@example.test')
            ->assertJsonPath('organisation.name', $this->org->getAttribute('name'));
    }

    #[Test]
    public function show_invitation_404s_for_expired_token(): void
    {
        $invitation = $this->seedInvitation($this->org->getKey(), 'expired@example.test');
        $invitation->update(['expires_at' => CarbonImmutable::now()->subMinute()]);

        $this->getJson(sprintf('/api/invitations/%s', $invitation->getAttribute('token')))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function accept_creates_user_attaches_to_org_and_signs_them_in(): void
    {
        $invitation = $this->seedInvitation($this->org->getKey(), 'newperson@example.test', OrganisationRole::Admin);

        $response = $this->postJson(sprintf('/api/invitations/%s/accept', $invitation->getAttribute('token')), [
            'name' => 'Brand New Person',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertOk();

        $userId = $response->json('user.id');
        $this->assertNotEmpty($userId);
        $this->assertSame('newperson@example.test', $response->json('user.email'));

        $newUser = User::query()->where('email', 'newperson@example.test')->first();
        $this->assertNotNull($newUser);
        $this->assertSame(
            'admin',
            (string) $newUser->organisations()->whereKey($this->org->getKey())->first()?->pivot->role,
        );

        $invitation->refresh();
        $this->assertNotNull($invitation->getAttribute('accepted_at'));
        $this->assertSame($newUser->getKey(), $invitation->getAttribute('accepted_user_id'));

        // Auth is established — /me succeeds without a fresh login.
        $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $newUser->getKey());
    }

    #[Test]
    public function accept_attaches_existing_user_without_changing_their_password(): void
    {
        $existing = User::create([
            'name' => 'Original Name',
            'email' => 'existing@example.test',
            'password' => Hash::make('original-password'),
        ]);
        $invitation = $this->seedInvitation($this->org->getKey(), 'existing@example.test');

        $this->postJson(sprintf('/api/invitations/%s/accept', $invitation->getAttribute('token')), [
            'name' => 'Ignored Name',
            'password' => 'ignored-password',
            'password_confirmation' => 'ignored-password',
        ])->assertOk();

        $fresh = $existing->fresh();
        $this->assertSame('Original Name', $fresh->getAttribute('name'));
        $this->assertTrue(Hash::check('original-password', (string) $fresh->getAuthPassword()));
        $this->assertTrue($fresh->organisations()->whereKey($this->org->getKey())->exists());
    }

    #[Test]
    public function accept_404s_when_token_already_used(): void
    {
        $invitation = $this->seedInvitation($this->org->getKey(), 'used@example.test');
        $invitation->update(['accepted_at' => CarbonImmutable::now()]);

        $this->postJson(sprintf('/api/invitations/%s/accept', $invitation->getAttribute('token')), [
            'name' => 'Late',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertStatus(Response::HTTP_NOT_FOUND);
    }

    private function seedInvitation(string $orgId, string $email, OrganisationRole $role = OrganisationRole::Viewer): UserInvitation
    {
        return UserInvitation::create([
            'organisation_id' => $orgId,
            'email' => $email,
            'role' => $role,
            'token' => UserInvitation::generateToken(),
            'expires_at' => CarbonImmutable::now()->addDays(UserInvitation::DEFAULT_TTL_DAYS),
            'invited_by_user_id' => $this->user->getKey(),
        ]);
    }
}
