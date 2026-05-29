<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ScanCadence;
use App\Enums\ScanTrigger;
use App\Jobs\ScanAccessibilityUrlsJob;
use App\Models\AccessibilityScan;
use App\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

/**
 * Mirror of {@see DispatchScheduledScansTest} for the accessibility
 * scheduler. The two pipelines share the cadence enum + stagger
 * algorithm so the tests look near-identical — that's deliberate, so
 * a regression in either dispatcher is easy to spot when reading.
 */
class DispatchScheduledA11yScansTest extends TenantTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/sitemap.xml' => Http::response('', 404),
            '*/robots.txt' => Http::response('', 404),
        ]);

        Bus::fake();
    }

    #[Test]
    public function domains_with_a11y_cadence_never_are_skipped(): void
    {
        Domain::factory()->for($this->org)->create([
            'fqdn' => 'never.example.com',
            'a11y_scan_cadence' => ScanCadence::Never,
        ]);

        $this->artisan('accessibility:dispatch-scheduled')->assertSuccessful();

        Bus::assertNothingBatched();
    }

    #[Test]
    public function weekly_never_scanned_dispatches_on_its_staggered_day(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'fqdn' => 'fresh.example.com',
            'a11y_scan_cadence' => ScanCadence::Weekly,
            'last_a11y_scanned_at' => null,
        ]);

        Date::setTestNow($this->dateForSlot(crc32((string) $domain->getKey()) % 7));

        $this->artisan('accessibility:dispatch-scheduled')->assertSuccessful();

        Bus::assertBatchCount(1);
        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->every(static fn (mixed $job): bool => $job instanceof ScanAccessibilityUrlsJob));
        $this->assertSame(1, AccessibilityScan::query()->count());
        $this->assertSame(
            ScanTrigger::Scheduled->value,
            AccessibilityScan::first()?->getAttribute('trigger')->value,
        );
    }

    #[Test]
    public function weekly_never_scanned_is_skipped_on_other_days(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'fqdn' => 'fresh.example.com',
            'a11y_scan_cadence' => ScanCadence::Weekly,
            'last_a11y_scanned_at' => null,
        ]);

        $domainSlot = crc32((string) $domain->getKey()) % 7;
        Date::setTestNow($this->dateForSlot(($domainSlot + 1) % 7));

        $this->artisan('accessibility:dispatch-scheduled')->assertSuccessful();

        Bus::assertNothingBatched();
    }

    #[Test]
    public function weekly_dispatches_when_last_a11y_scan_is_older_than_interval(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-05-24T05:00:00Z'));

        Domain::factory()->for($this->org)->create([
            'fqdn' => 'stale.example.com',
            'a11y_scan_cadence' => ScanCadence::Weekly,
            'last_a11y_scanned_at' => Date::now()->subDays(8),
        ]);

        $this->artisan('accessibility:dispatch-scheduled')->assertSuccessful();

        Bus::assertBatchCount(1);
    }

    #[Test]
    public function weekly_is_skipped_when_last_a11y_scan_is_within_interval(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-05-24T05:00:00Z'));

        Domain::factory()->for($this->org)->create([
            'fqdn' => 'recent.example.com',
            'a11y_scan_cadence' => ScanCadence::Weekly,
            'last_a11y_scanned_at' => Date::now()->subDays(3),
        ]);

        $this->artisan('accessibility:dispatch-scheduled')->assertSuccessful();

        Bus::assertNothingBatched();
    }

    #[Test]
    public function cookie_scan_cadence_does_not_trigger_a11y_dispatch(): void
    {
        // Domain has weekly COOKIE scans configured but accessibility
        // stays at Never. Accessibility scheduler must not pick it up.
        Domain::factory()->for($this->org)->create([
            'fqdn' => 'cookies-only.example.com',
            'scan_cadence' => ScanCadence::Weekly,
            'a11y_scan_cadence' => ScanCadence::Never,
            'last_scanned_at' => null,
            'last_a11y_scanned_at' => null,
        ]);

        Date::setTestNow(CarbonImmutable::parse('2026-05-24T05:00:00Z'));

        $this->artisan('accessibility:dispatch-scheduled')->assertSuccessful();

        Bus::assertNothingBatched();
    }

    #[Test]
    public function loops_across_all_organisations(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-05-24T05:00:00Z'));

        Domain::factory()->for($this->org)->create([
            'fqdn' => 'one.example.com',
            'a11y_scan_cadence' => ScanCadence::Weekly,
            'last_a11y_scanned_at' => Date::now()->subDays(10),
        ]);
        Domain::factory()->for($this->otherOrg)->create([
            'fqdn' => 'two.example.com',
            'a11y_scan_cadence' => ScanCadence::Weekly,
            'last_a11y_scanned_at' => Date::now()->subDays(10),
        ]);

        $this->artisan('accessibility:dispatch-scheduled')->assertSuccessful();

        Bus::assertBatchCount(2);
    }

    private function dateForSlot(int $slot): CarbonImmutable
    {
        return CarbonImmutable::createFromDate(2026, 1, 1)
            ->addDays($slot + 6)
            ->startOfDay()
            ->setTime(5, 0);
    }
}
