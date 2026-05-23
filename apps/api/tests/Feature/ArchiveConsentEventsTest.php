<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ArchiveConsentEventsJob;
use App\Models\ConsentEvent;
use App\Models\Domain;
use App\Services\ConsentArchive\ArchiveConsentEventsService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

class ArchiveConsentEventsTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-05-23T12:00:00Z');
        $this->domain = Domain::factory()->for($this->org)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(string $createdAt, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'domain_id' => $this->domain->getKey(),
            'organisation_id' => $this->org->getKey(),
            'banner_config_version' => 1,
            'anonymous_id' => 'anon-'.Str::random(6),
            'categories' => ['necessary' => true, 'preferences' => false, 'statistics' => false, 'marketing' => false],
            'gcm_signals' => ['analytics_storage' => 'denied'],
            'country_code' => 'GB',
            'user_agent_hash' => 'ua-hash',
            'ip_hash' => 'ip-hash',
            'event_type' => 'granted',
            'occurred_at' => $createdAt,
            'banner_text_hash' => 'banner-hash',
            'created_at' => $createdAt,
        ], $overrides);
    }

    #[Test]
    public function archives_rows_older_than_the_retention_window(): void
    {
        $oldId = (string) Str::uuid();
        $freshId = (string) Str::uuid();

        // 100 days ago — past the 90-day default.
        $this->d1->seedRow($this->row('2026-02-12T00:00:00Z', ['id' => $oldId]));
        // 10 days ago — well within retention.
        $this->d1->seedRow($this->row('2026-05-13T00:00:00Z', ['id' => $freshId]));

        $result = $this->app->make(ArchiveConsentEventsService::class)->archive();

        $this->assertSame(1, $result->upserted);
        $this->assertSame(1, $result->deleted);
        $this->assertSame(0, $result->skipped);

        // Old row landed in MySQL.
        $this->assertTrue(ConsentEvent::query()->withoutGlobalScope('organisation')->whereKey($oldId)->exists());

        // Old row gone from D1; fresh row still there.
        $remaining = collect($this->d1->all())->pluck('id')->all();
        $this->assertNotContains($oldId, $remaining);
        $this->assertContains($freshId, $remaining);
    }

    #[Test]
    public function rerunning_the_archive_is_idempotent_on_uuid(): void
    {
        $id = (string) Str::uuid();
        // Already in MySQL (edge mirror landed earlier).
        ConsentEvent::query()->withoutGlobalScope('organisation')->create([
            'id' => $id,
            'domain_id' => $this->domain->getKey(),
            'organisation_id' => $this->org->getKey(),
            'banner_config_version' => 1,
            'anonymous_id' => 'anon-pre',
            'categories' => ['necessary' => true, 'preferences' => false, 'statistics' => false, 'marketing' => false],
            'gcm_signals' => ['analytics_storage' => 'denied'],
            'country_code' => 'GB',
            'user_agent_hash' => 'ua',
            'ip_hash' => 'ip',
            'event_type' => 'granted',
            'occurred_at' => '2026-02-12T00:00:00Z',
            'banner_text_hash' => 'hash',
        ]);

        // Same UUID also sitting in D1 (mirror was best-effort; we keep it
        // until the archive sweeps).
        $this->d1->seedRow($this->row('2026-02-12T00:00:00Z', ['id' => $id]));

        $this->app->make(ArchiveConsentEventsService::class)->archive();

        // Still exactly one row in MySQL.
        $this->assertSame(
            1,
            ConsentEvent::query()->withoutGlobalScope('organisation')->whereKey($id)->count(),
        );
        // And the D1 row is gone.
        $this->assertSame([], $this->d1->all());
    }

    #[Test]
    public function pages_through_more_rows_than_the_batch_size(): void
    {
        // Seed 12 old rows + 1 fresh, batch size = 5 → service should do
        // 3 round-trips through D1 before stopping.
        for ($index = 0; $index < 12; $index++) {
            $this->d1->seedRow($this->row('2026-02-12T00:00:00Z'));
        }
        $this->d1->seedRow($this->row('2026-05-20T00:00:00Z')); // fresh

        $result = $this->app->make(ArchiveConsentEventsService::class)
            ->archive(retentionDays: 90, batchSize: 5);

        $this->assertSame(12, $result->upserted);
        $this->assertSame(12, $result->deleted);
        $this->assertSame(1, count($this->d1->all()));
    }

    #[Test]
    public function rows_without_a_resolvable_organisation_are_skipped(): void
    {
        // domain_id refers to nothing we know about — can't resolve org_id.
        $this->d1->seedRow($this->row('2026-02-12T00:00:00Z', [
            'domain_id' => (string) Str::uuid(),
            'organisation_id' => null,
        ]));

        $result = $this->app->make(ArchiveConsentEventsService::class)->archive();

        $this->assertSame(0, $result->upserted);
        $this->assertSame(1, $result->skipped);
        // Row stays in D1 since we couldn't safely move it. Next run can
        // pick it up once the domain shows up (or it ages out forever).
        $this->assertCount(1, $this->d1->all());
    }

    #[Test]
    public function the_queued_job_runs_the_service(): void
    {
        $this->d1->seedRow($this->row('2026-02-12T00:00:00Z'));

        (new ArchiveConsentEventsJob)->handle($this->app->make(ArchiveConsentEventsService::class));

        $this->assertSame(0, count($this->d1->all()));
        $this->assertSame(1, ConsentEvent::query()->withoutGlobalScope('organisation')->count());
    }

    #[Test]
    public function the_artisan_command_reports_counters(): void
    {
        $this->d1->seedRow($this->row('2026-02-12T00:00:00Z'));

        $this->artisan('consent-events:archive')
            ->expectsOutputToContain('upserted=1 deleted=1 skipped=0')
            ->assertSuccessful();
    }
}
