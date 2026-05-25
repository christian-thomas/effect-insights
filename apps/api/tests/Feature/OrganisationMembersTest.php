<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrganisationRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class OrganisationMembersTest extends TenantTestCase
{
    #[Test]
    public function list_requires_superuser(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/organisations/%s/users', $this->org->getKey()))
            ->assertStatus(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function superuser_can_list_org_members(): void
    {
        $this->makeSuperuser($this->user);

        $extra = User::create([
            'name' => 'Second Member',
            'email' => 'second@example.test',
            'password' => Hash::make('password'),
        ]);
        $this->org->users()->attach($extra, ['role' => OrganisationRole::Admin->value]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/organisations/%s/users', $this->org->getKey()))
            ->assertOk();

        $emails = collect($response->json('data'))->pluck('email')->all();
        $this->assertContains($this->user->getAttribute('email'), $emails);
        $this->assertContains('second@example.test', $emails);
        $this->assertNotContains($this->otherUser->getAttribute('email'), $emails);
    }

    #[Test]
    public function remove_member_detaches_user_from_org(): void
    {
        $this->makeSuperuser($this->user);

        $extra = User::create([
            'name' => 'To Remove',
            'email' => 'toremove@example.test',
            'password' => Hash::make('password'),
        ]);
        $this->org->users()->attach($extra, ['role' => OrganisationRole::Viewer->value]);

        $this->actingAsMember($this->user, $this->org)
            ->deleteJson(sprintf('/api/organisations/%s/users/%s', $this->org->getKey(), $extra->getKey()))
            ->assertStatus(Response::HTTP_NO_CONTENT);

        $this->assertDatabaseMissing('organisation_user', [
            'organisation_id' => $this->org->getKey(),
            'user_id' => $extra->getKey(),
        ]);
        // The user row stays — they may still belong to other orgs.
        $this->assertDatabaseHas('users', ['id' => $extra->getKey()]);
    }

    #[Test]
    public function remove_self_blocked_when_last_member_of_org(): void
    {
        $this->makeSuperuser($this->user);
        // Org has only one member ($this->user); also seed a second
        // superuser so the "last superuser" guard doesn't intercept first.
        $secondSuperuser = User::create([
            'name' => 'Second Super',
            'email' => 'second-super@example.test',
            'password' => Hash::make('password'),
        ]);
        $this->makeSuperuser($secondSuperuser);

        $this->actingAsMember($this->user, $this->org)
            ->deleteJson(sprintf('/api/organisations/%s/users/%s', $this->org->getKey(), $this->user->getKey()))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->assertDatabaseHas('organisation_user', [
            'organisation_id' => $this->org->getKey(),
            'user_id' => $this->user->getKey(),
        ]);
    }

    #[Test]
    public function remove_self_blocked_when_only_superuser(): void
    {
        $this->makeSuperuser($this->user);
        // Add a second org member so the "last member" guard doesn't fire.
        $coMember = User::create([
            'name' => 'Co Member',
            'email' => 'comember@example.test',
            'password' => Hash::make('password'),
        ]);
        $this->org->users()->attach($coMember, ['role' => OrganisationRole::Viewer->value]);

        $this->actingAsMember($this->user, $this->org)
            ->deleteJson(sprintf('/api/organisations/%s/users/%s', $this->org->getKey(), $this->user->getKey()))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        // Membership intact + still a superuser.
        $this->assertDatabaseHas('organisation_user', [
            'organisation_id' => $this->org->getKey(),
            'user_id' => $this->user->getKey(),
        ]);
        $this->assertTrue($this->user->fresh()?->hasRole(User::ROLE_SUPERUSER));
    }

    #[Test]
    public function remove_self_allowed_when_other_members_and_other_superusers_exist(): void
    {
        $this->makeSuperuser($this->user);
        // Second superuser so the platform-admin guard passes.
        $secondSuperuser = User::create([
            'name' => 'Second Super',
            'email' => 'second-super@example.test',
            'password' => Hash::make('password'),
        ]);
        $this->makeSuperuser($secondSuperuser);
        // Second org member so the last-member guard passes.
        $coMember = User::create([
            'name' => 'Co Member',
            'email' => 'comember@example.test',
            'password' => Hash::make('password'),
        ]);
        $this->org->users()->attach($coMember, ['role' => OrganisationRole::Viewer->value]);

        $this->actingAsMember($this->user, $this->org)
            ->deleteJson(sprintf('/api/organisations/%s/users/%s', $this->org->getKey(), $this->user->getKey()))
            ->assertStatus(Response::HTTP_NO_CONTENT);

        $this->assertDatabaseMissing('organisation_user', [
            'organisation_id' => $this->org->getKey(),
            'user_id' => $this->user->getKey(),
        ]);
        // The user record stays + superuser role stays.
        $this->assertDatabaseHas('users', ['id' => $this->user->getKey()]);
        $this->assertTrue($this->user->fresh()?->hasRole(User::ROLE_SUPERUSER));
    }
}
