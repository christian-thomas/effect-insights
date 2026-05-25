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
    public function remove_member_rejects_self_removal(): void
    {
        $this->makeSuperuser($this->user);

        $this->actingAsMember($this->user, $this->org)
            ->deleteJson(sprintf('/api/organisations/%s/users/%s', $this->org->getKey(), $this->user->getKey()))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        // Membership intact.
        $this->assertDatabaseHas('organisation_user', [
            'organisation_id' => $this->org->getKey(),
            'user_id' => $this->user->getKey(),
        ]);
    }
}
