<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\A11yAlertScope;
use App\Enums\ScanTrigger;
use App\Models\Domain;
use App\Notifications\AccessibilityIssuesFound;
use App\Services\Accessibility\AccessibilityScannerResult;
use App\Services\Accessibility\AccessibilityScanRunner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

/**
 * Per-org a11y_alert_scope: 'all' keeps the existing behaviour where
 * every finding contributes to the threshold count. 'wcag_only' drops
 * best-practice findings before counting.
 *
 * Tag predicate (mirrors the admin UI):
 *   - WCAG       — any tag starts with `wcag`
 *   - BP-only    — has `best-practice` tag AND no WCAG tag
 *   - Both       — has WCAG tag and `best-practice` tag → treated as WCAG
 */
class TagAwareA11yAlertTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.slack.notifications.channel', '#cmp-ops');
        Config::set('services.accessibility.alert_threshold', 'serious');
        Notification::fake();
        $this->domain = Domain::factory()->for($this->org)->create();
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<string, mixed>
     */
    private function finding(string $ruleId, string $impact, array $tags): array
    {
        return [
            'rule_id' => $ruleId,
            'impact' => $impact,
            'description' => 'd', 'help_text' => 'h', 'help_url' => 'https://example.test/h',
            'url' => 'https://example.test/', 'tags' => $tags, 'nodes' => [],
        ];
    }

    #[Test]
    public function default_scope_all_counts_best_practice_findings(): void
    {
        // Org left at default — schema migration sets a11y_alert_scope='all'.
        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1, urlsScanned: 1,
            findings: [
                $this->finding('region', 'serious', ['best-practice']),
            ],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(AccessibilityIssuesFound::class);
    }

    #[Test]
    public function wcag_only_scope_drops_best_practice_findings(): void
    {
        $this->org->update(['a11y_alert_scope' => A11yAlertScope::WcagOnly->value]);

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1, urlsScanned: 1,
            findings: [
                // Only a best-practice finding at-threshold. With wcag_only,
                // this should be filtered out and no alert should fire.
                $this->finding('region', 'serious', ['best-practice']),
            ],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertNothingSent();
    }

    #[Test]
    public function wcag_only_scope_keeps_wcag_findings(): void
    {
        $this->org->update(['a11y_alert_scope' => A11yAlertScope::WcagOnly->value]);

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1, urlsScanned: 1,
            findings: [
                // Mix: BP gets dropped, WCAG keeps the alert alive.
                $this->finding('region', 'serious', ['best-practice']),
                $this->finding('color-contrast', 'serious', ['wcag2aa', 'wcag143']),
            ],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(AccessibilityIssuesFound::class, function (
            AccessibilityIssuesFound $notification,
        ): bool {
            // The BP one got dropped; only color-contrast counted.
            $this->assertSame(1, $notification->total);
            $this->assertSame(1, $notification->byImpact['serious']);

            return true;
        });
    }

    #[Test]
    public function findings_with_both_wcag_and_best_practice_tags_count_as_wcag(): void
    {
        $this->org->update(['a11y_alert_scope' => A11yAlertScope::WcagOnly->value]);

        $this->a11yScanner->setNext(new AccessibilityScannerResult(
            urlsDiscovered: 1, urlsScanned: 1,
            findings: [
                // Rare but real: a finding tagged both. Should NOT be
                // suppressed — legally-required findings always win.
                $this->finding('color-contrast', 'serious', ['wcag2aa', 'best-practice']),
            ],
        ));

        $this->app->make(AccessibilityScanRunner::class)
            ->run($this->domain, ['https://example.test/'], ScanTrigger::Manual);

        Notification::assertSentOnDemand(AccessibilityIssuesFound::class);
    }

    #[Test]
    public function update_organisation_endpoint_persists_scope(): void
    {
        $superuser = $this->makeSuperuser($this->user);

        $response = $this->actingAsMember($superuser, $this->org)
            ->patchJson(sprintf('/api/organisations/%s', $this->org->getKey()), [
                'a11y_alert_scope' => 'wcag_only',
            ])
            ->assertOk();

        $this->assertSame('wcag_only', $response->json('data.a11yAlertScope'));
    }

    #[Test]
    public function update_organisation_rejects_invalid_scope(): void
    {
        $superuser = $this->makeSuperuser($this->user);

        $this->actingAsMember($superuser, $this->org)
            ->patchJson(sprintf('/api/organisations/%s', $this->org->getKey()), [
                'a11y_alert_scope' => 'nonsense',
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['a11y_alert_scope']);
    }
}
