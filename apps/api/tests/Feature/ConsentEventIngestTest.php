<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ConsentEvent;
use App\Models\Domain;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ConsentEventIngestTest extends TestCase
{
    use RefreshDatabase;

    private const INGEST_SECRET = 'test_ingest_secret_value';

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.consent_ingest.secret' => self::INGEST_SECRET]);

        $organisation = Organisation::create([
            'name' => 'Effect Digital',
            'slug' => 'effect-digital',
        ]);

        $this->domain = Domain::factory()->for($organisation)->create([
            'public_key' => 'pk_test_ingest_abc',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validBody(?string $eventId = null): array
    {
        return [
            'id' => $eventId ?? (string) Str::uuid(),
            'domainPublicKey' => 'pk_test_ingest_abc',
            'bannerConfigVersion' => 1,
            'anonymousId' => 'anon_abc',
            'categories' => [
                'necessary' => true,
                'preferences' => false,
                'statistics' => true,
                'marketing' => false,
            ],
            'gcmSignals' => [
                'analytics_storage' => 'granted',
                'ad_storage' => 'denied',
                'ad_user_data' => 'denied',
                'ad_personalization' => 'denied',
                'functionality_storage' => 'granted',
                'personalization_storage' => 'denied',
                'security_storage' => 'granted',
            ],
            'countryCode' => 'GB',
            'userAgentHash' => 'ua_hash',
            'ipHash' => 'ip_hash',
            'eventType' => ConsentEvent::EVENT_GRANTED,
            'occurredAt' => '2026-05-22T12:00:00Z',
            'bannerTextHash' => 'btxt_v1',
        ];
    }

    #[Test]
    public function ingest_rejects_requests_without_the_shared_secret(): void
    {
        $this->postJson('/api/internal/consent-events', $this->validBody())
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function ingest_rejects_a_wrong_secret(): void
    {
        $this->withHeader('X-Effect-Ingest-Secret', 'wrong')
            ->postJson('/api/internal/consent-events', $this->validBody())
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function ingest_persists_a_valid_event(): void
    {
        $body = $this->validBody();

        $this->withHeader('X-Effect-Ingest-Secret', self::INGEST_SECRET)
            ->postJson('/api/internal/consent-events', $body)
            ->assertStatus(Response::HTTP_ACCEPTED)
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('consent_events', [
            'id' => $body['id'],
            'domain_id' => $this->domain->getKey(),
            'organisation_id' => $this->domain->getAttribute('organisation_id'),
            'anonymous_id' => 'anon_abc',
            'event_type' => ConsentEvent::EVENT_GRANTED,
            'country_code' => 'GB',
        ]);
    }

    #[Test]
    public function ingest_is_idempotent_on_replay(): void
    {
        $body = $this->validBody('11111111-1111-4111-a111-111111111111');

        $this->withHeader('X-Effect-Ingest-Secret', self::INGEST_SECRET)
            ->postJson('/api/internal/consent-events', $body)
            ->assertStatus(Response::HTTP_ACCEPTED);

        $this->withHeader('X-Effect-Ingest-Secret', self::INGEST_SECRET)
            ->postJson('/api/internal/consent-events', $body)
            ->assertStatus(Response::HTTP_ACCEPTED);

        $this->assertDatabaseCount('consent_events', 1);
    }

    #[Test]
    public function ingest_validates_required_fields(): void
    {
        $this->withHeader('X-Effect-Ingest-Secret', self::INGEST_SECRET)
            ->postJson('/api/internal/consent-events', [])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors([
                'id', 'domainPublicKey', 'bannerConfigVersion', 'anonymousId',
                'categories', 'gcmSignals', 'userAgentHash', 'ipHash',
                'eventType', 'occurredAt', 'bannerTextHash',
            ]);
    }

    #[Test]
    public function ingest_rejects_an_unknown_public_key(): void
    {
        $body = array_merge($this->validBody(), ['domainPublicKey' => 'pk_does_not_exist']);

        $this->withHeader('X-Effect-Ingest-Secret', self::INGEST_SECRET)
            ->postJson('/api/internal/consent-events', $body)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['domainPublicKey']);
    }
}
