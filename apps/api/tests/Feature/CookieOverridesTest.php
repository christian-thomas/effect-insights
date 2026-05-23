<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CookieCategory;
use App\Models\CookieOverride;
use App\Models\Domain;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class CookieOverridesTest extends TenantTestCase
{
    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = Domain::factory()->for($this->org)->create();
    }

    #[Test]
    public function listing_returns_overrides_for_the_current_organisation_only(): void
    {
        CookieOverride::create([
            'organisation_id' => $this->org->getKey(),
            'name_pattern' => '_ga_*',
            'category' => CookieCategory::Marketing,
        ]);
        CookieOverride::create([
            'organisation_id' => $this->otherOrg->getKey(),
            'name_pattern' => '_fbp',
            'category' => CookieCategory::Statistics,
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/cookie-overrides')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.namePattern', '_ga_*');
    }

    #[Test]
    public function filter_to_org_wide_overrides_only(): void
    {
        CookieOverride::create([
            'organisation_id' => $this->org->getKey(),
            'name_pattern' => '_ga_*',
            'category' => CookieCategory::Marketing,
        ]);
        CookieOverride::create([
            'organisation_id' => $this->org->getKey(),
            'domain_id' => $this->domain->getKey(),
            'name_pattern' => '_hjid',
            'category' => CookieCategory::Preferences,
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->getJson('/api/cookie-overrides?domain_id=org')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.namePattern', '_ga_*');
    }

    #[Test]
    public function creates_an_org_wide_override(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-overrides', [
                'name_pattern' => '_ga_*',
                'category' => CookieCategory::Marketing->value,
                'purpose' => 'We treat GA as marketing internally',
            ])
            ->assertStatus(Response::HTTP_CREATED)
            ->assertJsonPath('data.namePattern', '_ga_*')
            ->assertJsonPath('data.category', 'marketing')
            ->assertJsonPath('data.domainId', null)
            ->assertJsonPath('data.createdByUserId', $this->user->getKey());

        $this->assertDatabaseHas('cookie_overrides', [
            'organisation_id' => $this->org->getKey(),
            'name_pattern' => '_ga_*',
            'domain_id' => null,
        ]);
    }

    #[Test]
    public function creates_a_domain_scoped_override(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-overrides', [
                'name_pattern' => '_hjid',
                'category' => CookieCategory::Statistics->value,
                'domain_id' => $this->domain->getKey(),
            ])
            ->assertStatus(Response::HTTP_CREATED)
            ->assertJsonPath('data.domainId', $this->domain->getKey());
    }

    #[Test]
    public function refuses_to_create_with_a_domain_from_another_organisation(): void
    {
        $foreignDomain = Domain::factory()->for($this->otherOrg)->create();

        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-overrides', [
                'name_pattern' => '_hjid',
                'category' => CookieCategory::Statistics->value,
                'domain_id' => $foreignDomain->getKey(),
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['domain_id']);
    }

    #[Test]
    public function the_same_pattern_can_have_both_an_org_and_a_domain_override(): void
    {
        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-overrides', [
                'name_pattern' => '_ga_*',
                'category' => CookieCategory::Marketing->value,
            ])
            ->assertStatus(Response::HTTP_CREATED);

        // Same pattern but scoped to a domain — should be allowed.
        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-overrides', [
                'name_pattern' => '_ga_*',
                'category' => CookieCategory::Statistics->value,
                'domain_id' => $this->domain->getKey(),
            ])
            ->assertStatus(Response::HTTP_CREATED);

        $this->assertSame(2, CookieOverride::query()->count());
    }

    #[Test]
    public function refuses_a_duplicate_pattern_within_the_same_scope(): void
    {
        CookieOverride::create([
            'organisation_id' => $this->org->getKey(),
            'name_pattern' => '_ga_*',
            'category' => CookieCategory::Marketing,
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-overrides', [
                'name_pattern' => '_ga_*',
                'category' => CookieCategory::Statistics->value,
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['name_pattern']);
    }

    #[Test]
    public function updates_the_category_on_an_existing_override(): void
    {
        $override = CookieOverride::create([
            'organisation_id' => $this->org->getKey(),
            'name_pattern' => '_ga_*',
            'category' => CookieCategory::Marketing,
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->patchJson(sprintf('/api/cookie-overrides/%s', $override->getKey()), [
                'category' => CookieCategory::Statistics->value,
                'purpose' => 'Reclassified after legal review',
            ])
            ->assertOk()
            ->assertJsonPath('data.category', 'statistics')
            ->assertJsonPath('data.purpose', 'Reclassified after legal review');
    }

    #[Test]
    public function deleting_an_override_removes_the_row(): void
    {
        $override = CookieOverride::create([
            'organisation_id' => $this->org->getKey(),
            'name_pattern' => '_ga_*',
            'category' => CookieCategory::Marketing,
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->deleteJson(sprintf('/api/cookie-overrides/%s', $override->getKey()))
            ->assertStatus(Response::HTTP_NO_CONTENT);

        $this->assertSame(0, CookieOverride::query()->count());
    }

    #[Test]
    public function cannot_update_an_override_belonging_to_another_organisation(): void
    {
        $foreignOverride = CookieOverride::create([
            'organisation_id' => $this->otherOrg->getKey(),
            'name_pattern' => '_ga_*',
            'category' => CookieCategory::Marketing,
        ]);

        $this->actingAsMember($this->user, $this->org)
            ->patchJson(sprintf('/api/cookie-overrides/%s', $foreignOverride->getKey()), [
                'category' => CookieCategory::Necessary->value,
            ])
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }
}
