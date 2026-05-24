<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ScanCadence;
use App\Enums\ScanTrigger;
use App\Jobs\RunScanJob;
use App\Models\Domain;
use App\Models\Scan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

class DispatchScheduledScansTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sitemap discovery: every domain falls back to apex-only.
        Http::fake([
            '*/sitemap.xml' => Http::response('', 404),
            '*/robots.txt' => Http::response('', 404),
        ]);

        Queue::fake();
    }

    #[Test]
    public function domains_with_cadence_never_are_skipped(): void
    {
        Domain::factory()->for($this->org)->create([
            'fqdn' => 'never.example.com',
            'scan_cadence' => ScanCadence::Never,
        ]);

        $this->artisan('scans:dispatch-scheduled')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function weekly_never_scanned_dispatches_on_its_staggered_day(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'fqdn' => 'fresh.example.com',
            'scan_cadence' => ScanCadence::Weekly,
            'last_scanned_at' => null,
        ]);

        // Make today's slot match the domain's stagger slot, so the command
        // sees it as due. crc32 % 7 ∈ [0, 6]; we just pick a year-day where
        // dayOfYear % 7 lands on that value.
        Date::setTestNow($this->dateForSlot(crc32((string) $domain->getKey()) % 7));

        $this->artisan('scans:dispatch-scheduled')->assertSuccessful();

        Queue::assertPushed(RunScanJob::class, 1);
        $this->assertSame(1, Scan::query()->count());
        $this->assertSame(ScanTrigger::Scheduled->value, Scan::first()?->getAttribute('trigger')->value);
    }

    #[Test]
    public function weekly_never_scanned_is_skipped_on_other_days(): void
    {
        $domain = Domain::factory()->for($this->org)->create([
            'fqdn' => 'fresh.example.com',
            'scan_cadence' => ScanCadence::Weekly,
            'last_scanned_at' => null,
        ]);

        // Pick a slot != domain's slot.
        $domainSlot = crc32((string) $domain->getKey()) % 7;
        $otherSlot = ($domainSlot + 1) % 7;
        Date::setTestNow($this->dateForSlot($otherSlot));

        $this->artisan('scans:dispatch-scheduled')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function weekly_dispatches_when_last_scan_is_older_than_interval(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-05-24T04:00:00Z'));

        Domain::factory()->for($this->org)->create([
            'fqdn' => 'stale.example.com',
            'scan_cadence' => ScanCadence::Weekly,
            'last_scanned_at' => Date::now()->subDays(8),
        ]);

        $this->artisan('scans:dispatch-scheduled')->assertSuccessful();

        Queue::assertPushed(RunScanJob::class, 1);
    }

    #[Test]
    public function weekly_is_skipped_when_last_scan_is_within_interval(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-05-24T04:00:00Z'));

        Domain::factory()->for($this->org)->create([
            'fqdn' => 'recent.example.com',
            'scan_cadence' => ScanCadence::Weekly,
            'last_scanned_at' => Date::now()->subDays(3),
        ]);

        $this->artisan('scans:dispatch-scheduled')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function loops_across_all_organisations(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-05-24T04:00:00Z'));

        Domain::factory()->for($this->org)->create([
            'fqdn' => 'one.example.com',
            'scan_cadence' => ScanCadence::Weekly,
            'last_scanned_at' => Date::now()->subDays(10),
        ]);
        Domain::factory()->for($this->otherOrg)->create([
            'fqdn' => 'two.example.com',
            'scan_cadence' => ScanCadence::Weekly,
            'last_scanned_at' => Date::now()->subDays(10),
        ]);

        $this->artisan('scans:dispatch-scheduled')->assertSuccessful();

        Queue::assertPushed(RunScanJob::class, 2);
    }

    private function dateForSlot(int $slot): CarbonImmutable
    {
        // Year 2026 starts on a Thursday (Jan 1 = dayOfYear 1). We just need
        // any date where dayOfYear % 7 equals the slot. dayOfYear=$slot+7 works.
        return CarbonImmutable::createFromDate(2026, 1, 1)->addDays($slot + 6)->startOfDay()->setTime(4, 0);
    }
}
