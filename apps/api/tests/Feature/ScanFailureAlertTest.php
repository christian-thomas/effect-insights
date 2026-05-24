<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanTrigger;
use App\Models\Domain;
use App\Notifications\ScanFailed;
use App\Services\Scanner\ScannerClientContract;
use App\Services\Scanner\ScannerResult;
use App\Services\Scanner\ScanRunner;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TenantTestCase;

class ScanFailureAlertTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.slack.notifications.channel', '#cmp-ops');
        Config::set('services.slack.notifications.bot_user_oauth_token', 'xoxb-test-token');

        Notification::fake();

        $this->domain = Domain::factory()->for($this->org)->create(['fqdn' => 'fails.example.test']);
    }

    private function bindFailingScanner(string $message = 'Browser binding timeout'): void
    {
        $failing = new class($message) implements ScannerClientContract
        {
            public function __construct(private readonly string $message) {}

            #[\Override]
            public function scan(Domain $domain, array $urls): ScannerResult
            {
                throw new RuntimeException($this->message);
            }
        };

        $this->app->instance(ScannerClientContract::class, $failing);
    }

    #[Test]
    public function sends_a_slack_alert_when_the_scanner_throws(): void
    {
        $this->bindFailingScanner('Browser binding timeout');

        $scan = $this->app->make(ScanRunner::class)
            ->run($this->domain, ['https://fails.example.test/'], ScanTrigger::Manual);

        $this->assertSame('failed', $scan->getAttribute('status')->value);

        Notification::assertSentOnDemand(
            ScanFailed::class,
            function (ScanFailed $notification, array $channels, AnonymousNotifiable $notifiable) use ($scan): bool {
                $this->assertSame(['slack'], $channels);
                $this->assertSame('#cmp-ops', $notifiable->routes['slack'] ?? null);
                $this->assertSame((string) $scan->getKey(), (string) $notification->scan->getKey());
                $this->assertSame('Browser binding timeout', $notification->errorMessage);
                $this->assertSame(RuntimeException::class, $notification->errorClass);
                $this->assertStringContainsString('/scans/', $notification->detailUrl);

                return true;
            },
        );
    }

    #[Test]
    public function does_not_alert_on_a_successful_scan(): void
    {
        // The default ArrayScannerClient returns an empty result, which the
        // runner treats as a successful completed scan.
        $this->app->make(ScanRunner::class)
            ->run($this->domain, ['https://fails.example.test/'], ScanTrigger::Manual);

        Notification::assertNothingSent();
    }

    #[Test]
    public function silently_no_ops_when_the_slack_channel_is_unconfigured(): void
    {
        Config::set('services.slack.notifications.channel', '');
        $this->bindFailingScanner();

        $this->app->make(ScanRunner::class)
            ->run($this->domain, ['https://fails.example.test/'], ScanTrigger::Manual);

        Notification::assertNothingSent();
    }

    #[Test]
    public function alerter_failure_does_not_overwrite_the_failed_scan_status(): void
    {
        // Bind a broken alerter that always throws. The original scan failure
        // path must still leave the row as failed with the original error.
        $this->bindFailingScanner('Real scanner exception');
        $this->app->bind(\App\Services\Notifications\ScanFailureAlertService::class, function (): \App\Services\Notifications\ScanFailureAlertService {
            return new readonly class extends \App\Services\Notifications\ScanFailureAlertService
            {
                #[\Override]
                public function alert(\App\Models\Scan $scan, Domain $domain, array $error): void
                {
                    throw new RuntimeException('alerter blew up');
                }
            };
        });

        $scan = $this->app->make(ScanRunner::class)
            ->run($this->domain, ['https://fails.example.test/'], ScanTrigger::Manual);

        $this->assertSame('failed', $scan->getAttribute('status')->value);
        $errorLog = $scan->getAttribute('error_log');
        $this->assertSame('Real scanner exception', $errorLog['message']);
        $this->assertSame(RuntimeException::class, $errorLog['class']);
    }
}
