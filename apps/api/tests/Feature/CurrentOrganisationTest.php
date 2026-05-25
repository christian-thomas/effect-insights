<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\A11yAlertScope;
use App\Enums\A11yImpact;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class CurrentOrganisationTest extends TenantTestCase
{
    #[Test]
    public function get_requires_auth(): void
    {
        $this->getJson('/api/current-organisation')
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function get_returns_the_active_orgs_settings(): void
    {
        $this->org->update([
            'consent_retention_days' => 365,
            'notification_email' => 'alerts@example.test',
            'a11y_alert_threshold' => A11yImpact::Critical->value,
            'a11y_alert_scope' => A11yAlertScope::WcagOnly->value,
        ]);

        $response = $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/current-organisation')
            ->assertOk();

        $this->assertSame($this->org->getKey(), $response->json('data.id'));
        $this->assertSame(365, $response->json('data.consentRetentionDays'));
        $this->assertSame('alerts@example.test', $response->json('data.notificationEmail'));
        $this->assertSame('critical', $response->json('data.a11yAlertThreshold'));
        $this->assertSame('wcag_only', $response->json('data.a11yAlertScope'));
    }

    #[Test]
    public function patch_updates_the_active_orgs_settings(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->patchJson('/api/current-organisation', [
                'consent_retention_days' => 90,
                'notification_email' => 'ops@example.test, alerts@example.test',
                'a11y_alert_threshold' => A11yImpact::Moderate->value,
                'a11y_alert_scope' => A11yAlertScope::All->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.consentRetentionDays', 90)
            ->assertJsonPath('data.notificationEmail', 'ops@example.test, alerts@example.test')
            ->assertJsonPath('data.a11yAlertThreshold', 'moderate')
            ->assertJsonPath('data.a11yAlertScope', 'all');

        $this->assertDatabaseHas('organisations', [
            'id' => $this->org->getKey(),
            'consent_retention_days' => 90,
            'notification_email' => 'ops@example.test, alerts@example.test',
            'a11y_alert_threshold' => A11yImpact::Moderate->value,
            'a11y_alert_scope' => A11yAlertScope::All->value,
        ]);
    }

    #[Test]
    public function patch_only_affects_the_active_org_not_other_tenants(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->patchJson('/api/current-organisation', [
                'notification_email' => 'changed@example.test',
            ])
            ->assertOk();

        $this->assertDatabaseHas('organisations', [
            'id' => $this->otherOrg->getKey(),
            'notification_email' => null,
        ]);
    }

    #[Test]
    public function patch_rejects_invalid_threshold_value(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->patchJson('/api/current-organisation', [
                'a11y_alert_threshold' => 'catastrophic',
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('a11y_alert_threshold');
    }

    #[Test]
    public function patch_does_not_expose_name_or_slug_for_editing(): void
    {
        $originalName = $this->org->getAttribute('name');
        $originalSlug = $this->org->getAttribute('slug');

        $this->actingAsMember($this->user, $this->org)
            ->patchJson('/api/current-organisation', [
                'name' => 'Hijacked Name',
                'slug' => 'hijacked',
                'notification_email' => 'ops@example.test',
            ])
            ->assertOk();

        // notification_email goes through, but name + slug are stripped by
        // the FormRequest's allow-list and untouched in the database.
        $fresh = $this->org->fresh();
        $this->assertSame($originalName, $fresh->getAttribute('name'));
        $this->assertSame($originalSlug, $fresh->getAttribute('slug'));
        $this->assertSame('ops@example.test', $fresh->getAttribute('notification_email'));
    }
}
