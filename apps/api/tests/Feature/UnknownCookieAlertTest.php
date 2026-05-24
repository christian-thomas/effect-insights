<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CookieCategory;
use App\Enums\CookieSource;
use App\Enums\ScanTrigger;
use App\Models\Cookie;
use App\Models\Domain;
use App\Notifications\UnknownCookieFound;
use App\Services\Scanner\ScannerResult;
use App\Services\Scanner\ScanRunner;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

class UnknownCookieAlertTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.slack.notifications.channel', '#cmp-ops');
        Config::set('services.slack.notifications.bot_user_oauth_token', 'xoxb-test-token');

        Notification::fake();

        $this->domain = Domain::factory()->for($this->org)->create(['fqdn' => 'example.test']);

        Cookie::create([
            'name_pattern' => '_known_*',
            'vendor' => 'Known Vendor',
            'category' => CookieCategory::Statistics,
            'purpose' => 'matched in canonical DB',
            'source' => CookieSource::Manual,
        ]);
    }

    private function runner(): ScanRunner
    {
        return $this->app->make(ScanRunner::class);
    }

    #[Test]
    public function sends_a_slack_notification_when_a_scan_finds_uncategorised_cookies(): void
    {
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            cookies: [
                ['name' => '_known_a', 'domain' => '.example.test', 'party' => 'third'],
                ['name' => '_mystery_one', 'domain' => '.tracker.test', 'party' => 'third'],
                ['name' => '_mystery_two', 'domain' => '.tracker.test', 'party' => 'third'],
            ],
            scripts: [],
            iframes: [],
            requests: [],
        ));

        $scan = $this->runner()->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(
            UnknownCookieFound::class,
            function (UnknownCookieFound $notification, array $channels, AnonymousNotifiable $notifiable) use ($scan): bool {
                $this->assertSame(['slack'], $channels);
                $this->assertSame('#cmp-ops', $notifiable->routes['slack'] ?? null);
                $this->assertSame((string) $scan->getKey(), (string) $notification->scan->getKey());
                $this->assertEqualsCanonicalizing(
                    ['_mystery_one', '_mystery_two'],
                    $notification->unknownCookieNames,
                );
                $this->assertStringContainsString('/cookies/review', $notification->reviewUrl);

                return true;
            },
        );
    }

    #[Test]
    public function does_not_notify_when_every_cookie_was_recognised(): void
    {
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            cookies: [
                ['name' => '_known_a', 'domain' => '.example.test', 'party' => 'third'],
                ['name' => '_known_b', 'domain' => '.example.test', 'party' => 'third'],
            ],
            scripts: [],
            iframes: [],
            requests: [],
        ));

        $this->runner()->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertNothingSent();
    }

    #[Test]
    public function ignores_non_cookie_findings_when_counting_unknowns(): void
    {
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            cookies: [
                ['name' => '_known_a', 'domain' => '.example.test', 'party' => 'third'],
            ],
            scripts: [
                ['name' => 'https://unknown-script.test/x.js', 'party' => 'third'],
            ],
            iframes: [
                ['name' => 'https://unknown-iframe.test/', 'party' => 'third'],
            ],
            requests: [],
        ));

        $this->runner()->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertNothingSent();
    }

    #[Test]
    public function dedupes_unknown_names_across_findings_with_the_same_name(): void
    {
        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 2,
            urlsScanned: 2,
            cookies: [
                ['name' => '_mystery_one', 'domain' => '.tracker.test', 'party' => 'third', 'first_seen_url' => 'https://example.test/a'],
                ['name' => '_mystery_one', 'domain' => '.tracker.test', 'party' => 'third', 'first_seen_url' => 'https://example.test/b'],
            ],
            scripts: [],
            iframes: [],
            requests: [],
        ));

        $this->runner()->run($this->domain, ['https://example.test/a', 'https://example.test/b'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(
            UnknownCookieFound::class,
            function (UnknownCookieFound $notification): bool {
                $this->assertSame(['_mystery_one'], $notification->unknownCookieNames);

                return true;
            },
        );
    }

    #[Test]
    public function silently_no_ops_when_the_slack_channel_is_unconfigured(): void
    {
        Config::set('services.slack.notifications.channel', '');

        $this->scanner->setNext(new ScannerResult(
            urlsDiscovered: 1,
            urlsScanned: 1,
            cookies: [
                ['name' => '_mystery_one', 'domain' => '.tracker.test', 'party' => 'third'],
            ],
            scripts: [],
            iframes: [],
            requests: [],
        ));

        $this->runner()->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertNothingSent();
    }
}
