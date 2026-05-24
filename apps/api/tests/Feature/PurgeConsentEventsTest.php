<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ConsentEvent;
use App\Models\Domain;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

class PurgeConsentEventsTest extends TenantTestCase
{
    #[Test]
    public function purge_no_ops_when_no_organisation_has_a_retention_window(): void
    {
        $domain = Domain::factory()->for($this->org)->create();
        ConsentEvent::factory()->for($domain)->create([
            'occurred_at' => CarbonImmutable::now()->subYears(5),
        ]);

        $this->artisan('consent-events:purge')
            ->expectsOutputToContain('No organisations have a retention window configured. Nothing to purge.')
            ->assertSuccessful();

        $this->assertDatabaseCount('consent_events', 1);
    }

    #[Test]
    public function purge_deletes_events_older_than_the_orgs_retention_days(): void
    {
        $this->org->update(['consent_retention_days' => 30]);
        $domain = Domain::factory()->for($this->org)->create();

        // 3 events: one fresh, one inside the window, one well past.
        $fresh = ConsentEvent::factory()->for($domain)->create([
            'occurred_at' => CarbonImmutable::now()->subDay(),
        ]);
        $borderline = ConsentEvent::factory()->for($domain)->create([
            'occurred_at' => CarbonImmutable::now()->subDays(29),
        ]);
        $expired = ConsentEvent::factory()->for($domain)->create([
            'occurred_at' => CarbonImmutable::now()->subDays(45),
        ]);

        $this->artisan('consent-events:purge')->assertSuccessful();

        $this->assertDatabaseHas('consent_events', ['id' => $fresh->getKey()]);
        $this->assertDatabaseHas('consent_events', ['id' => $borderline->getKey()]);
        $this->assertDatabaseMissing('consent_events', ['id' => $expired->getKey()]);
    }

    #[Test]
    public function purge_is_scoped_per_organisation(): void
    {
        $this->org->update(['consent_retention_days' => 30]);
        // otherOrg keeps the indefinite default — its old rows must survive.

        $ourDomain = Domain::factory()->for($this->org)->create();
        $theirDomain = Domain::factory()->for($this->otherOrg)->create();

        $ourExpired = ConsentEvent::factory()->for($ourDomain)->create([
            'occurred_at' => CarbonImmutable::now()->subDays(90),
        ]);
        $theirAncient = ConsentEvent::factory()->for($theirDomain)->create([
            'occurred_at' => CarbonImmutable::now()->subYears(2),
        ]);

        $this->artisan('consent-events:purge')->assertSuccessful();

        $this->assertDatabaseMissing('consent_events', ['id' => $ourExpired->getKey()]);
        $this->assertDatabaseHas('consent_events', ['id' => $theirAncient->getKey()]);
    }

    #[Test]
    public function purge_is_idempotent_when_re_run(): void
    {
        $this->org->update(['consent_retention_days' => 30]);
        $domain = Domain::factory()->for($this->org)->create();
        ConsentEvent::factory()->for($domain)->create([
            'occurred_at' => CarbonImmutable::now()->subDays(45),
        ]);

        $this->artisan('consent-events:purge')->assertSuccessful();
        $this->assertDatabaseCount('consent_events', 0);

        // Second run finds nothing to delete and stays successful.
        $this->artisan('consent-events:purge')->assertSuccessful();
        $this->assertDatabaseCount('consent_events', 0);
    }

    #[Test]
    public function update_organisation_endpoint_can_set_retention_days(): void
    {
        $superuser = $this->makeSuperuser($this->user);

        $response = $this->actingAsMember($superuser, $this->org)
            ->patchJson(sprintf('/api/organisations/%s', $this->org->getKey()), [
                'consent_retention_days' => 90,
            ])
            ->assertOk();

        $this->assertSame(90, $response->json('data.consentRetentionDays'));
        $this->assertSame(90, $this->org->fresh()->getAttribute('consent_retention_days'));
    }

    #[Test]
    public function update_organisation_endpoint_can_clear_retention_to_indefinite(): void
    {
        $this->org->update(['consent_retention_days' => 90]);
        $superuser = $this->makeSuperuser($this->user);

        $response = $this->actingAsMember($superuser, $this->org)
            ->patchJson(sprintf('/api/organisations/%s', $this->org->getKey()), [
                'consent_retention_days' => null,
            ])
            ->assertOk();

        $this->assertNull($response->json('data.consentRetentionDays'));
        $this->assertNull($this->org->fresh()->getAttribute('consent_retention_days'));
    }
}
