<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\AccessibilityScan;
use App\Models\Domain;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class AccessibilityTrendTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create();
    }

    private function makeScan(int $daysAgo, int $critical = 0, int $serious = 0): AccessibilityScan
    {
        $finishedAt = CarbonImmutable::now()->subDays($daysAgo);

        return AccessibilityScan::create([
            'domain_id' => $this->domain->getKey(),
            'status' => ScanStatus::Completed,
            'trigger' => ScanTrigger::Scheduled,
            'urls_discovered' => 5,
            'urls_scanned' => 5,
            'violations_total' => $critical + $serious,
            'violations_by_impact' => [
                'critical' => $critical,
                'serious' => $serious,
                'moderate' => 0,
                'minor' => 0,
            ],
            'started_at' => $finishedAt->subMinutes(2),
            'finished_at' => $finishedAt,
        ]);
    }

    #[Test]
    public function returns_one_data_point_per_completed_scan_in_window(): void
    {
        $this->makeScan(daysAgo: 5, critical: 1, serious: 2);
        $this->makeScan(daysAgo: 12, critical: 0, serious: 3);
        // Out of window — should not appear with default 90-day query.
        $this->makeScan(daysAgo: 200, critical: 5);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/accessibility-trend', $this->domain->getKey()))
            ->assertOk();

        $points = $response->json('data.points');
        $this->assertCount(2, $points);

        // Oldest-first ordering.
        $this->assertSame(0, $points[0]['byImpact']['critical']);
        $this->assertSame(3, $points[0]['byImpact']['serious']);
        $this->assertSame(1, $points[1]['byImpact']['critical']);
    }

    #[Test]
    public function days_param_is_honoured(): void
    {
        $this->makeScan(daysAgo: 5);
        $this->makeScan(daysAgo: 20);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/accessibility-trend?days=10', $this->domain->getKey()))
            ->assertOk();

        $this->assertCount(1, $response->json('data.points'));
        $this->assertSame(10, $response->json('data.days'));
    }

    #[Test]
    public function failed_scans_are_excluded(): void
    {
        $this->makeScan(daysAgo: 5, critical: 1);

        AccessibilityScan::create([
            'domain_id' => $this->domain->getKey(),
            'status' => ScanStatus::Failed,
            'trigger' => ScanTrigger::Scheduled,
            'urls_discovered' => 0,
            'urls_scanned' => 0,
            'violations_total' => 0,
            'started_at' => CarbonImmutable::now()->subDays(3),
            'finished_at' => CarbonImmutable::now()->subDays(3),
            'error_log' => ['message' => 'boom', 'class' => 'X'],
        ]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/accessibility-trend', $this->domain->getKey()))
            ->assertOk();

        $this->assertCount(1, $response->json('data.points'));
    }

    #[Test]
    public function empty_window_returns_empty_array(): void
    {
        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/accessibility-trend', $this->domain->getKey()))
            ->assertOk();

        $this->assertSame([], $response->json('data.points'));
    }

    #[Test]
    public function tenant_cannot_read_another_orgs_trend(): void
    {
        $foreign = Domain::factory()->for($this->otherOrg)->create();
        AccessibilityScan::create([
            'domain_id' => $foreign->getKey(),
            'status' => ScanStatus::Completed,
            'trigger' => ScanTrigger::Scheduled,
            'urls_discovered' => 1, 'urls_scanned' => 1,
            'violations_total' => 0,
            'started_at' => CarbonImmutable::now(),
            'finished_at' => CarbonImmutable::now(),
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->getJson(sprintf('/api/domains/%s/accessibility-trend', $foreign->getKey()))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }
}
