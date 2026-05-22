<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organisation;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class OrganisationCrudTest extends TenantTestCase
{
    #[Test]
    public function regular_users_cannot_list_organisations(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/organisations')
            ->assertStatus(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function superusers_can_list_every_organisation(): void
    {
        Organisation::factory()->count(3)->create();
        $this->makeSuperuser($this->user);

        $response = $this->actingAs($this->user)->getJson('/api/organisations');

        // 3 new + 2 seeded by TenantTestCase = 5
        $response->assertOk()->assertJsonCount(5, 'data');
    }

    #[Test]
    public function superusers_can_create_an_organisation(): void
    {
        $this->makeSuperuser($this->user);

        $response = $this->actingAs($this->user)->postJson('/api/organisations', [
            'name' => 'New Client',
            'slug' => 'new-client',
        ]);

        $response->assertStatus(Response::HTTP_CREATED)
            ->assertJsonPath('data.name', 'New Client')
            ->assertJsonPath('data.slug', 'new-client');

        $this->assertDatabaseHas('organisations', ['slug' => 'new-client']);
    }

    #[Test]
    public function slugs_must_be_unique(): void
    {
        $this->makeSuperuser($this->user);
        Organisation::factory()->create(['slug' => 'taken']);

        $this->actingAs($this->user)->postJson('/api/organisations', [
            'name' => 'Whatever',
            'slug' => 'taken',
        ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['slug']);
    }

    #[Test]
    public function superusers_can_update_and_delete_an_organisation(): void
    {
        $this->makeSuperuser($this->user);
        $target = Organisation::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->user)
            ->patchJson(sprintf('/api/organisations/%s', $target->getKey()), [
                'name' => 'New Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->actingAs($this->user)
            ->deleteJson(sprintf('/api/organisations/%s', $target->getKey()))
            ->assertStatus(Response::HTTP_NO_CONTENT);

        $this->assertDatabaseMissing('organisations', ['id' => $target->getKey()]);
    }
}
