<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class OrganisationSwitchTest extends TenantTestCase
{
    #[Test]
    public function login_response_includes_current_organisation_id(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => $this->user->getAttribute('email'),
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('currentOrganisationId', $this->org->getKey());
    }

    #[Test]
    public function a_member_can_switch_to_an_organisation_they_belong_to(): void
    {
        $secondOrg = Organisation::factory()->create();
        $this->user->organisations()->attach($secondOrg, ['role' => OrganisationRole::Viewer]);

        $this->actingAs($this->user);

        $response = $this->postJson('/api/organisations/switch', [
            'organisation_id' => $secondOrg->getKey(),
        ]);

        $response->assertOk()
            ->assertJsonPath('currentOrganisationId', $secondOrg->getKey());
    }

    #[Test]
    public function a_member_cannot_switch_to_an_organisation_they_do_not_belong_to(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/organisations/switch', [
            'organisation_id' => $this->otherOrg->getKey(),
        ]);

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function a_superuser_can_switch_to_any_organisation(): void
    {
        $this->makeSuperuser($this->user);
        $this->actingAs($this->user);

        $response = $this->postJson('/api/organisations/switch', [
            'organisation_id' => $this->otherOrg->getKey(),
        ]);

        $response->assertOk()
            ->assertJsonPath('currentOrganisationId', $this->otherOrg->getKey());
    }

    #[Test]
    public function switching_organisation_persists_for_subsequent_requests(): void
    {
        $secondOrg = Organisation::factory()->create();
        $this->user->organisations()->attach($secondOrg, ['role' => OrganisationRole::Owner]);

        $this->actingAs($this->user);

        $this->postJson('/api/organisations/switch', [
            'organisation_id' => $secondOrg->getKey(),
        ])->assertOk();

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('currentOrganisationId', $secondOrg->getKey());
    }
}
