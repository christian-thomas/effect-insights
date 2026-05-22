<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Domain;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class DomainsTest extends TenantTestCase
{
    #[Test]
    public function listing_domains_returns_only_the_current_organisation(): void
    {
        Domain::factory()->for($this->org)->count(2)->create();
        Domain::factory()->for($this->otherOrg)->count(3)->create();

        $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/domains')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_domain_belonging_to_another_organisation_returns_404(): void
    {
        $foreignDomain = Domain::factory()->for($this->otherOrg)->create();

        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s', $foreignDomain->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function creating_a_domain_attaches_it_to_the_current_organisation(): void
    {
        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/domains', [
                'fqdn' => 'example.test',
            ]);

        $response->assertStatus(Response::HTTP_CREATED)
            ->assertJsonPath('data.fqdn', 'example.test')
            ->assertJsonPath('data.apexDomain', 'example.test')
            ->assertJsonPath('data.status', Domain::STATUS_PENDING);

        $this->assertDatabaseHas('domains', [
            'fqdn' => 'example.test',
            'organisation_id' => $this->org->getKey(),
        ]);
    }

    #[Test]
    public function creating_a_domain_generates_a_public_key_and_apex(): void
    {
        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/domains', [
                'fqdn' => 'shop.acme-corp.example.test',
            ]);

        $response->assertStatus(Response::HTTP_CREATED)
            ->assertJsonPath('data.apexDomain', 'example.test');

        $publicKey = $response->json('data.publicKey');
        $this->assertIsString($publicKey);
        $this->assertStringStartsWith('pk_', $publicKey);
    }

    #[Test]
    public function fqdn_must_be_unique_within_an_organisation(): void
    {
        Domain::factory()->for($this->org)->create(['fqdn' => 'example.test']);

        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/domains', ['fqdn' => 'example.test'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['fqdn']);
    }

    #[Test]
    public function the_same_fqdn_can_be_used_by_different_organisations(): void
    {
        Domain::factory()->for($this->otherOrg)->create(['fqdn' => 'example.test']);

        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/domains', ['fqdn' => 'example.test'])
            ->assertStatus(Response::HTTP_CREATED);
    }

    #[Test]
    public function updating_a_domain_in_the_current_organisation_works(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'status' => Domain::STATUS_PENDING,
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->patchJson(sprintf('/api/domains/%s', $domain->getKey()), [
                'status' => Domain::STATUS_ACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Domain::STATUS_ACTIVE);
    }

    #[Test]
    public function deleting_a_domain_returns_204(): void
    {
        $domain = Domain::factory()->for($this->org)->create();

        $this->actingAsMember($this->user, $this->org)
            ->deleteJson(sprintf('/api/domains/%s', $domain->getKey()))
            ->assertStatus(Response::HTTP_NO_CONTENT);

        $this->assertDatabaseMissing('domains', ['id' => $domain->getKey()]);
    }

    #[Test]
    public function a_superuser_sees_the_organisation_they_are_scoped_into(): void
    {
        Domain::factory()->for($this->org)->count(2)->create();
        Domain::factory()->for($this->otherOrg)->count(5)->create();

        $superuser = $this->makeSuperuser($this->user);

        $this->actingAsMember($superuser, $this->otherOrg)
            ->getJson('/api/domains')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }
}
