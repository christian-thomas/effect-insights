<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CookieCategory;
use App\Enums\CookieSource;
use App\Models\Cookie;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Curated starter set of well-known cookies. Hand-picked so the scanner +
 * resolver have something to match against on day one — the full OpenCookieDB
 * import lands later as a separate `cookies:import` artisan command that
 * stages rows into `cookie_imports` for human review.
 *
 * Patterns use `*` for the variable parts (`_ga_*` covers every GA4 property,
 * `__hssrc_*` covers HubSpot session-row cookies, etc.). Keep this list
 * deliberately small + canonical — it's not meant to replace OCD.
 */
class CookieSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach ($this->records() as $record) {
            Cookie::firstOrCreate(
                [
                    'name_pattern' => $record['name_pattern'],
                    'source' => CookieSource::Manual,
                    'source_version' => null,
                ],
                $record,
            );
        }
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function records(): iterable
    {
        // Google Analytics 4
        yield $this->row('_ga', 'Google Analytics', 'google-analytics.com', CookieCategory::Statistics,
            'Distinguishes unique visitors across GA-tracked sites.', '2 years');
        yield $this->row('_ga_*', 'Google Analytics', 'google-analytics.com', CookieCategory::Statistics,
            'GA4 property-scoped session identifier (replaces the older _gid).', '2 years');
        yield $this->row('_gid', 'Google Analytics', 'google-analytics.com', CookieCategory::Statistics,
            'GA visitor identifier rotated daily.', '24 hours');
        yield $this->row('_gat*', 'Google Analytics', 'google-analytics.com', CookieCategory::Statistics,
            'Throttles request rate to GA.', '1 minute');
        yield $this->row('__utm*', 'Google Analytics (legacy)', 'google-analytics.com', CookieCategory::Statistics,
            'Universal Analytics campaign + session tracking. Legacy — GA4 superseded.', '6 months – 2 years');

        // Google Tag Manager + Ads
        yield $this->row('_gcl_*', 'Google Ads', 'google.com', CookieCategory::Marketing,
            'Google Click Identifier for ad conversion attribution.', '90 days');
        yield $this->row('IDE', 'Google DoubleClick', 'doubleclick.net', CookieCategory::Marketing,
            'Cross-site ad targeting + conversion measurement.', '13 months');
        yield $this->row('NID', 'Google', 'google.com', CookieCategory::Preferences,
            'Stores Google preferences (language, search settings, SafeSearch).', '6 months');
        yield $this->row('test_cookie', 'Google DoubleClick', 'doubleclick.net', CookieCategory::Necessary,
            'Detects whether the browser supports cookies.', '15 minutes');

        // Meta / Facebook
        yield $this->row('_fbp', 'Meta (Facebook)', 'facebook.com', CookieCategory::Marketing,
            'Facebook Pixel browser identifier for ad measurement.', '90 days');
        yield $this->row('_fbc', 'Meta (Facebook)', 'facebook.com', CookieCategory::Marketing,
            'Last-click identifier from a Facebook ad to a conversion.', '90 days');
        yield $this->row('fr', 'Meta (Facebook)', 'facebook.com', CookieCategory::Marketing,
            'Encrypted Facebook ID + browser info for targeting.', '90 days');

        // HubSpot
        yield $this->row('__hstc', 'HubSpot', 'hubspot.com', CookieCategory::Statistics,
            'Visitor tracking — origin, count of visits, current visit timestamp.', '6 months');
        yield $this->row('__hssc', 'HubSpot', 'hubspot.com', CookieCategory::Statistics,
            'HubSpot session identifier.', '30 minutes');
        yield $this->row('__hssrc', 'HubSpot', 'hubspot.com', CookieCategory::Statistics,
            'Tracks whether the visitor has restarted their browser.', 'session');
        yield $this->row('hubspotutk', 'HubSpot', 'hubspot.com', CookieCategory::Statistics,
            'Visitor identifier tied to HubSpot CRM contact records.', '6 months');

        // LinkedIn
        yield $this->row('li_*', 'LinkedIn', 'linkedin.com', CookieCategory::Marketing,
            'LinkedIn Insight Tag — conversion + retargeting identifiers.', '30 days – 2 years');
        yield $this->row('bcookie', 'LinkedIn', 'linkedin.com', CookieCategory::Marketing,
            'Browser identifier for LinkedIn ad attribution.', '1 year');
        yield $this->row('lidc', 'LinkedIn', 'linkedin.com', CookieCategory::Necessary,
            'Routes LinkedIn requests to the appropriate data centre.', '24 hours');

        // Microsoft / Bing
        yield $this->row('MUID', 'Microsoft', 'bing.com', CookieCategory::Marketing,
            'Cross-Microsoft-property user identifier for advertising.', '13 months');
        yield $this->row('_uetsid', 'Microsoft (UET)', 'bing.com', CookieCategory::Marketing,
            'Bing UET session identifier.', '24 hours');
        yield $this->row('_uetvid', 'Microsoft (UET)', 'bing.com', CookieCategory::Marketing,
            'Bing UET visitor identifier.', '13 months');

        // X / Twitter
        yield $this->row('personalization_id', 'X (Twitter)', 'x.com', CookieCategory::Marketing,
            'Cross-site ad personalisation identifier.', '2 years');

        // Hotjar
        yield $this->row('_hjSessionUser_*', 'Hotjar', 'hotjar.com', CookieCategory::Statistics,
            'Persistent visitor identifier for Hotjar session recordings.', '1 year');
        yield $this->row('_hjSession_*', 'Hotjar', 'hotjar.com', CookieCategory::Statistics,
            'Per-session Hotjar identifier.', '30 minutes');
        yield $this->row('_hjid', 'Hotjar (legacy)', 'hotjar.com', CookieCategory::Statistics,
            'Legacy Hotjar visitor identifier. Replaced by _hjSessionUser_*.', '1 year');

        // Stripe (necessary — payments)
        yield $this->row('__stripe_mid', 'Stripe', 'stripe.com', CookieCategory::Necessary,
            'Fraud-detection device identifier (machine ID).', '1 year');
        yield $this->row('__stripe_sid', 'Stripe', 'stripe.com', CookieCategory::Necessary,
            'Per-session Stripe fraud-detection identifier.', '30 minutes');

        // Intercom
        yield $this->row('intercom-id-*', 'Intercom', 'intercom.com', CookieCategory::Statistics,
            'Intercom anonymous visitor identifier.', '9 months');
        yield $this->row('intercom-session-*', 'Intercom', 'intercom.com', CookieCategory::Statistics,
            'Intercom logged-in session identifier.', '7 days');

        // Cloudflare (necessary)
        yield $this->row('__cf_bm', 'Cloudflare', 'cloudflare.com', CookieCategory::Necessary,
            'Cloudflare bot-management identifier — required for site security.', '30 minutes');
        yield $this->row('cf_clearance', 'Cloudflare', 'cloudflare.com', CookieCategory::Necessary,
            'Stores the result of a Cloudflare challenge so the visitor isn\'t re-challenged.', '1 year');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $namePattern,
        string $vendor,
        string $vendorDomain,
        CookieCategory $category,
        string $purpose,
        string $expiryHint,
    ): array {
        return [
            'name_pattern' => $namePattern,
            'vendor' => $vendor,
            'vendor_domain' => $vendorDomain,
            'category' => $category,
            'purpose' => $purpose,
            'expiry_hint' => $expiryHint,
            'source' => CookieSource::Manual,
            'source_version' => null,
        ];
    }
}
