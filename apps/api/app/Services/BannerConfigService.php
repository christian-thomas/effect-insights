<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BannerConfig;
use App\Models\Domain;
use App\Models\User;
use App\Services\Cloudflare\CloudflareKvClientContract;
use App\Support\BannerConfigPayloadBuilder;
use App\Support\MaterialChangeDetector;
use Illuminate\Support\Facades\DB;

/**
 * Owns the lifecycle of banner_configs for a given Domain.
 *
 * Versioning model — append-only with an explicit draft row:
 *   - draft   = (is_draft=true,  is_published=false) — at most one per domain
 *   - active  = (is_draft=false, is_published=true)  — the currently-served version
 *   - history = (is_draft=false, is_published=false) — past published versions
 *
 * Editing mutates the draft. Publish locks the draft (flips flags) and clones
 * a new draft. Audit trail preserved.
 */
class BannerConfigService
{
    public const DEFAULT_LOCALE = 'en';

    public function __construct(
        private readonly CloudflareKvClientContract $kv,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function defaultColors(): array
    {
        return [
            'background' => '#ffffff',
            'text' => '#111827',
            'primary' => '#2563eb',
            'primaryText' => '#ffffff',
            'secondary' => '#f3f4f6',
            'secondaryText' => '#111827',
        ];
    }

    /**
     * Default per-locale copy. Banner copy is keyed by ISO 639-1 language code
     * so the editor can edit multiple locales in tabs and the runtime can
     * pick the right one based on document.lang / config.localeDefault.
     *
     * @return array<string, mixed>
     */
    public static function defaultCopy(): array
    {
        return [
            self::DEFAULT_LOCALE => self::defaultLocaleCopy(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultLocaleCopy(): array
    {
        return [
            'title' => 'We use cookies',
            'body' => 'We use cookies to make the site work, understand how it\'s used, and to personalise content. Choose which cookies you accept.',
            'acceptAllLabel' => 'Accept all',
            'rejectAllLabel' => 'Reject all',
            'customiseLabel' => 'Customise',
            'savePreferencesLabel' => 'Save preferences',
            'categoryDescriptions' => [
                'necessary' => 'Required for the site to function. Cannot be disabled.',
                'preferences' => 'Remember choices you make (language, region, etc).',
                'statistics' => 'Anonymous analytics about how the site is used.',
                'marketing' => 'Personalised advertising and tracking.',
            ],
        ];
    }

    /**
     * GDPR-compliant defaults: deny everything except functionality + security
     * (the Google Consent Mode signals that cover essential site behaviour).
     *
     * @return array<string, string>
     */
    public static function defaultGcmDefaults(): array
    {
        return [
            'ad_storage' => 'denied',
            'analytics_storage' => 'denied',
            'ad_user_data' => 'denied',
            'ad_personalization' => 'denied',
            'functionality_storage' => 'granted',
            'personalization_storage' => 'denied',
            'security_storage' => 'granted',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultGeoRules(): array
    {
        return [
            'mode' => 'always',
            'countries' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultBehaviour(): array
    {
        return [
            'rePromptAfterDays' => 365,
            'honourGpc' => true,
            'autoDenyOnDismiss' => false,
            'crossSubdomainConsent' => false,
        ];
    }

    public function getOrCreateDraft(Domain $domain): BannerConfig
    {
        return DB::transaction(function () use ($domain): BannerConfig {
            $existing = BannerConfig::query()
                ->where('domain_id', $domain->getKey())
                ->where('is_draft', true)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $publishedSource = BannerConfig::query()
                ->where('domain_id', $domain->getKey())
                ->where('is_published', true)
                ->orderByDesc('version')
                ->first();

            if ($publishedSource !== null) {
                return BannerConfig::create([
                    'domain_id' => $domain->getKey(),
                    'version' => 0,
                    'is_draft' => true,
                    'is_published' => false,
                    'layout' => $publishedSource->getAttribute('layout'),
                    'locale_default' => $publishedSource->getAttribute('locale_default'),
                    'locales_enabled' => $publishedSource->getAttribute('locales_enabled'),
                    'colors' => $publishedSource->getAttribute('colors'),
                    'copy' => $publishedSource->getAttribute('copy'),
                    'gcm_defaults' => $publishedSource->getAttribute('gcm_defaults'),
                    'geo_rules' => $publishedSource->getAttribute('geo_rules'),
                    'behaviour' => $publishedSource->getAttribute('behaviour'),
                ]);
            }

            return BannerConfig::create([
                'domain_id' => $domain->getKey(),
                'version' => 0,
                'is_draft' => true,
                'is_published' => false,
                'layout' => BannerConfig::LAYOUT_MODAL,
                'locale_default' => self::DEFAULT_LOCALE,
                'locales_enabled' => [self::DEFAULT_LOCALE],
                'colors' => self::defaultColors(),
                'copy' => self::defaultCopy(),
                'gcm_defaults' => self::defaultGcmDefaults(),
                'geo_rules' => self::defaultGeoRules(),
                'behaviour' => self::defaultBehaviour(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateDraft(Domain $domain, array $attributes): BannerConfig
    {
        $draft = $this->getOrCreateDraft($domain);
        $draft->fill($attributes);
        $draft->save();

        return $draft->refresh();
    }

    public function publish(Domain $domain, User $user): BannerConfig
    {
        return DB::transaction(function () use ($domain, $user): BannerConfig {
            // Lock the domain row so concurrent publish calls serialise on it.
            $lockedDomain = Domain::query()
                ->withoutGlobalScope('organisation')
                ->whereKey($domain->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $draft = BannerConfig::query()
                ->where('domain_id', $lockedDomain->getKey())
                ->where('is_draft', true)
                ->lockForUpdate()
                ->firstOrFail();

            $previousPublished = BannerConfig::query()
                ->where('domain_id', $lockedDomain->getKey())
                ->where('is_published', true)
                ->first();

            $nextVersion = (int) BannerConfig::query()
                ->where('domain_id', $lockedDomain->getKey())
                ->max('version') + 1;

            // material_version bumps only on a material diff (GCM defaults,
            // geo rules, compliance-behaviour flags, locale set). First-ever
            // publish counts as the initial material version.
            $nextMaterialVersion = match (true) {
                $previousPublished === null => 1,
                MaterialChangeDetector::isMaterial($previousPublished, $draft) => (int) $previousPublished->getAttribute('material_version') + 1,
                default => (int) $previousPublished->getAttribute('material_version'),
            };

            // Demote any currently-published config to history.
            BannerConfig::query()
                ->where('domain_id', $lockedDomain->getKey())
                ->where('is_published', true)
                ->update(['is_published' => false]);

            // Promote the draft to the published version.
            $draft->update([
                'is_draft' => false,
                'is_published' => true,
                'version' => $nextVersion,
                'material_version' => $nextMaterialVersion,
                'published_at' => now(),
                'created_by_user_id' => $user->getKey(),
            ]);

            // Clone a fresh draft for further edits — carry every field
            // forward so the editor opens to the just-published state.
            // material_version is carried so a cosmetic-only republish from
            // the cloned draft won't accidentally re-bump.
            BannerConfig::create([
                'domain_id' => $lockedDomain->getKey(),
                'version' => 0,
                'material_version' => $nextMaterialVersion,
                'is_draft' => true,
                'is_published' => false,
                'layout' => $draft->getAttribute('layout'),
                'locale_default' => $draft->getAttribute('locale_default'),
                'locales_enabled' => $draft->getAttribute('locales_enabled'),
                'colors' => $draft->getAttribute('colors'),
                'copy' => $draft->getAttribute('copy'),
                'gcm_defaults' => $draft->getAttribute('gcm_defaults'),
                'geo_rules' => $draft->getAttribute('geo_rules'),
                'behaviour' => $draft->getAttribute('behaviour'),
            ]);

            $this->pushToEdge($draft->refresh());

            return $draft;
        });
    }

    /**
     * Publishes the canonical banner payload to Cloudflare KV under the
     * domain's public_key. The edge worker (apps/edge config-worker) reads
     * from the same namespace and serves it to cmp.js.
     *
     * Throws on failure — `publish()` runs inside a DB transaction so if KV
     * is unreachable the row flips are rolled back too. Acceptable for v0:
     * better to fail loud than have admin/edge drift silently.
     */
    public function pushToEdge(BannerConfig $bannerConfig): void
    {
        $domain = Domain::query()
            ->withoutGlobalScope('organisation')
            ->whereKey($bannerConfig->getAttribute('domain_id'))
            ->firstOrFail();

        $payload = BannerConfigPayloadBuilder::build($bannerConfig, $domain);

        $this->kv->putValue($domain->getAttribute('public_key'), $payload);
    }
}
