<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConsentEventType;
use App\Models\ConsentEvent;
use App\Models\Domain;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

class ConsentEventsCsvExportTest extends TenantTestCase
{
    private Domain $domain;

    private Domain $otherDomain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create();
        $this->otherDomain = Domain::factory()->for($this->otherOrg)->create();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parseCsv(string $body): array
    {
        $rows = [];
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return $rows;
        }
        fwrite($handle, $body);
        rewind($handle);
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_map(static fn (mixed $value): string => (string) $value, $row);
        }
        fclose($handle);

        return $rows;
    }

    #[Test]
    public function csv_export_includes_a_header_row_with_stable_columns(): void
    {
        ConsentEvent::factory()->for($this->domain)->create();

        $response = $this->actingAsMember($this->user, $this->org)
            ->get('/api/consent-events.csv');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=', (string) $response->headers->get('Content-Disposition'));

        $rows = $this->parseCsv((string) $response->streamedContent());
        $this->assertGreaterThan(1, count($rows));
        $this->assertSame([
            'id',
            'occurred_at',
            'domain_id',
            'organisation_id',
            'banner_config_version',
            'anonymous_id',
            'event_type',
            'country_code',
            'categories',
            'gcm_signals',
            'banner_text_hash',
            'user_agent_hash',
            'ip_hash',
            'created_at',
        ], $rows[0]);
    }

    #[Test]
    public function csv_export_writes_one_row_per_event(): void
    {
        ConsentEvent::factory()->for($this->domain)->count(5)->create();

        $response = $this->actingAsMember($this->user, $this->org)
            ->get('/api/consent-events.csv')
            ->assertOk();

        $rows = $this->parseCsv((string) $response->streamedContent());
        // header + 5 data rows
        $this->assertCount(6, $rows);
    }

    #[Test]
    public function csv_export_respects_the_global_tenant_scope(): void
    {
        ConsentEvent::factory()->for($this->domain)->count(2)->create();
        ConsentEvent::factory()->for($this->otherDomain)->count(7)->create();

        $response = $this->actingAsMember($this->user, $this->org)
            ->get('/api/consent-events.csv')
            ->assertOk();

        $rows = $this->parseCsv((string) $response->streamedContent());
        $this->assertCount(3, $rows); // header + 2 own
    }

    #[Test]
    public function csv_export_honours_domain_id_and_event_type_filters(): void
    {
        $secondDomain = Domain::factory()->for($this->org)->create();
        ConsentEvent::factory()->for($this->domain)->count(3)->create([
            'event_type' => ConsentEventType::Granted,
        ]);
        ConsentEvent::factory()->for($secondDomain)->count(2)->create([
            'event_type' => ConsentEventType::Denied,
        ]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->get(sprintf(
                '/api/consent-events.csv?domain_id=%s&event_type=denied',
                $secondDomain->getKey(),
            ))
            ->assertOk();

        $rows = $this->parseCsv((string) $response->streamedContent());
        $this->assertCount(3, $rows); // header + 2 denied
    }

    #[Test]
    public function csv_export_honours_from_and_to_date_filters(): void
    {
        ConsentEvent::factory()->for($this->domain)->create([
            'occurred_at' => CarbonImmutable::parse('2026-01-15T12:00:00Z'),
        ]);
        ConsentEvent::factory()->for($this->domain)->create([
            'occurred_at' => CarbonImmutable::parse('2026-03-15T12:00:00Z'),
        ]);
        ConsentEvent::factory()->for($this->domain)->create([
            'occurred_at' => CarbonImmutable::parse('2026-06-15T12:00:00Z'),
        ]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->get('/api/consent-events.csv?from=2026-02-01&to=2026-05-01')
            ->assertOk();

        $rows = $this->parseCsv((string) $response->streamedContent());
        $this->assertCount(2, $rows); // header + 1 in-window
    }

    #[Test]
    public function categories_and_gcm_signals_are_serialised_as_json_in_their_csv_columns(): void
    {
        ConsentEvent::factory()->for($this->domain)->create([
            'categories' => ['necessary' => true, 'statistics' => true, 'marketing' => false, 'preferences' => false],
            'gcm_signals' => ['ad_storage' => 'denied', 'analytics_storage' => 'granted'],
        ]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->get('/api/consent-events.csv')
            ->assertOk();

        $rows = $this->parseCsv((string) $response->streamedContent());
        $dataRow = $rows[1];
        $categoriesIndex = array_search('categories', $rows[0], true);
        $gcmIndex = array_search('gcm_signals', $rows[0], true);

        $this->assertIsInt($categoriesIndex);
        $this->assertIsInt($gcmIndex);

        $categories = json_decode($dataRow[$categoriesIndex], true);
        $gcm = json_decode($dataRow[$gcmIndex], true);

        $this->assertSame(true, $categories['statistics'] ?? null);
        $this->assertSame('granted', $gcm['analytics_storage'] ?? null);
    }
}
