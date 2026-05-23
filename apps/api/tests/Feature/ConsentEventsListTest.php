<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ConsentEvent;
use App\Models\Domain;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class ConsentEventsListTest extends TenantTestCase
{
    private Domain $domain;

    private Domain $otherDomain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create();
        $this->otherDomain = Domain::factory()->for($this->otherOrg)->create();
    }

    #[Test]
    public function listing_consent_events_returns_only_the_current_organisation(): void
    {
        ConsentEvent::factory()->for($this->domain)->count(3)->create();
        ConsentEvent::factory()->for($this->otherDomain)->count(5)->create();

        $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/consent-events')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    #[Test]
    public function consent_events_are_ordered_newest_first(): void
    {
        $older = ConsentEvent::factory()
            ->for($this->domain)
            ->create(['occurred_at' => CarbonImmutable::parse('2026-01-01T12:00:00Z')]);
        $newer = ConsentEvent::factory()
            ->for($this->domain)
            ->create(['occurred_at' => CarbonImmutable::parse('2026-05-01T12:00:00Z')]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/consent-events')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$newer->getKey(), $older->getKey()], $ids);
    }

    #[Test]
    public function consent_events_can_be_filtered_by_domain(): void
    {
        $secondDomain = Domain::factory()->for($this->org)->create();
        ConsentEvent::factory()->for($this->domain)->count(2)->create();
        ConsentEvent::factory()->for($secondDomain)->count(4)->create();

        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/consent-events?domain_id=%s', $secondDomain->getKey()))
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    #[Test]
    public function consent_events_can_be_filtered_by_anonymous_id(): void
    {
        ConsentEvent::factory()->for($this->domain)->create(['anonymous_id' => 'anon_target']);
        ConsentEvent::factory()->for($this->domain)->count(3)->create();

        $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/consent-events?anonymous_id=anon_target')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.anonymousId', 'anon_target');
    }

    #[Test]
    public function consent_events_can_be_filtered_by_event_type(): void
    {
        ConsentEvent::factory()->for($this->domain)->count(2)->create(['event_type' => ConsentEvent::EVENT_GRANTED]);
        ConsentEvent::factory()->for($this->domain)->count(3)->create(['event_type' => ConsentEvent::EVENT_DENIED]);

        $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/consent-events?event_type=denied')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function consent_events_can_be_filtered_by_date_range(): void
    {
        ConsentEvent::factory()
            ->for($this->domain)
            ->create(['occurred_at' => CarbonImmutable::parse('2026-01-15T12:00:00Z')]);
        ConsentEvent::factory()
            ->for($this->domain)
            ->create(['occurred_at' => CarbonImmutable::parse('2026-03-15T12:00:00Z')]);
        ConsentEvent::factory()
            ->for($this->domain)
            ->create(['occurred_at' => CarbonImmutable::parse('2026-05-15T12:00:00Z')]);

        $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/consent-events?from=2026-02-01&to=2026-04-30')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function cross_tenant_domain_filter_returns_empty(): void
    {
        ConsentEvent::factory()->for($this->otherDomain)->count(2)->create();

        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/consent-events?domain_id=%s', $this->otherDomain->getKey()))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function pagination_caps_per_page(): void
    {
        ConsentEvent::factory()->for($this->domain)->count(120)->create();

        $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/consent-events?per_page=500')
            ->assertOk()
            ->assertJsonCount(100, 'data');

        $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/consent-events?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 120);
    }

    #[Test]
    public function unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/consent-events')
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }
}
