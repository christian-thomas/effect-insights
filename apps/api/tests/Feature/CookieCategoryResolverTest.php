<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CookieCategory;
use App\Enums\CookieResolutionSource;
use App\Enums\CookieSource;
use App\Models\Cookie;
use App\Models\CookieOverride;
use App\Models\Domain;
use App\Services\Cookies\CookieCategoryResolver;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use Tests\TenantTestCase;

class CookieCategoryResolverTest extends TenantTestCase
{
    private CookieCategoryResolver $resolver;

    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = $this->app->make(CookieCategoryResolver::class);
        $this->domain = Domain::factory()->for($this->org)->create();

        Cookie::create([
            'name_pattern' => '_ga_*',
            'vendor' => 'Google Analytics',
            'category' => CookieCategory::Statistics,
            'purpose' => 'GA4 property-scoped session identifier',
            'source' => CookieSource::Manual,
        ]);
    }

    #[Test]
    public function returns_null_when_no_pattern_matches(): void
    {
        $resolution = $this->resolver->resolve('totally_unknown_cookie', $this->domain, $this->org);

        $this->assertNull($resolution);
    }

    #[Test]
    public function falls_back_to_canonical_when_no_overrides_exist(): void
    {
        $resolution = $this->resolver->resolve('_ga_ABC123', $this->domain, $this->org);

        $this->assertNotNull($resolution);
        $this->assertSame(CookieResolutionSource::Canonical, $resolution->source);
        $this->assertSame(CookieCategory::Statistics, $resolution->category);
        $this->assertSame('_ga_*', $resolution->matchedPattern);
    }

    #[Test]
    public function organisation_override_wins_over_canonical(): void
    {
        CookieOverride::create([
            'organisation_id' => $this->org->getKey(),
            'domain_id' => null,
            'name_pattern' => '_ga_*',
            'category' => CookieCategory::Marketing,
            'purpose' => 'We treat GA as marketing internally',
        ]);

        $resolution = $this->resolver->resolve('_ga_ABC123', $this->domain, $this->org);

        $this->assertNotNull($resolution);
        $this->assertSame(CookieResolutionSource::OrganisationOverride, $resolution->source);
        $this->assertSame(CookieCategory::Marketing, $resolution->category);
    }

    #[Test]
    public function domain_override_wins_over_organisation_override(): void
    {
        CookieOverride::create([
            'organisation_id' => $this->org->getKey(),
            'domain_id' => null,
            'name_pattern' => '_ga_*',
            'category' => CookieCategory::Marketing,
        ]);

        CookieOverride::create([
            'organisation_id' => $this->org->getKey(),
            'domain_id' => $this->domain->getKey(),
            'name_pattern' => '_ga_*',
            'category' => CookieCategory::Preferences,
        ]);

        $resolution = $this->resolver->resolve('_ga_ABC123', $this->domain, $this->org);

        $this->assertNotNull($resolution);
        $this->assertSame(CookieResolutionSource::DomainOverride, $resolution->source);
        $this->assertSame(CookieCategory::Preferences, $resolution->category);
    }

    #[Test]
    public function overrides_from_other_organisations_do_not_leak(): void
    {
        // An override on $otherOrg should NOT affect $org's resolution.
        CookieOverride::create([
            'organisation_id' => $this->otherOrg->getKey(),
            'domain_id' => null,
            'name_pattern' => '_ga_*',
            'category' => CookieCategory::Marketing,
        ]);

        $resolution = $this->resolver->resolve('_ga_ABC123', $this->domain, $this->org);

        // Falls through to canonical, not the other org's override.
        $this->assertNotNull($resolution);
        $this->assertSame(CookieResolutionSource::Canonical, $resolution->source);
        $this->assertSame(CookieCategory::Statistics, $resolution->category);
    }

    #[Test]
    public function works_with_organisation_only_no_domain(): void
    {
        $resolution = $this->resolver->resolve('_ga_ABC123', null, $this->org);

        $this->assertNotNull($resolution);
        $this->assertSame(CookieResolutionSource::Canonical, $resolution->source);
    }

    #[Test]
    public function works_with_no_context_at_all(): void
    {
        $resolution = $this->resolver->resolve('_ga_ABC123');

        $this->assertNotNull($resolution);
        $this->assertSame(CookieResolutionSource::Canonical, $resolution->source);
    }

    #[Test]
    public function the_most_recent_canonical_row_wins_when_multiple_patterns_match(): void
    {
        // Insert a more recent override row for the same pattern that
        // re-categorises it. supersedes_id is informational; the resolver
        // simply orders by created_at desc, so the newer row wins. Time
        // travel one minute forward to make the ordering deterministic
        // (otherwise both rows land in the same wall-clock second).
        Date::setTestNow(now()->addMinute());

        Cookie::create([
            'name_pattern' => '_ga_*',
            'vendor' => 'Google Analytics',
            'category' => CookieCategory::Marketing,
            'purpose' => 'Revised categorisation',
            'source' => CookieSource::Manual,
            'source_version' => '2',
        ]);

        $resolution = $this->resolver->resolve('_ga_ABC123');

        $this->assertNotNull($resolution);
        $this->assertSame(CookieCategory::Marketing, $resolution->category);
    }
}
