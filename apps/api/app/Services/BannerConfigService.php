<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BannerConfig;
use App\Models\Domain;
use App\Models\User;
use App\Services\Cloudflare\CloudflareKvClientContract;
use App\Support\BannerConfigPayloadBuilder;
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
     * @return array<string, mixed>
     */
    public static function defaultCopy(): array
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

            return BannerConfig::create([
                'domain_id' => $domain->getKey(),
                'version' => 0,
                'is_draft' => true,
                'is_published' => false,
                'colors' => $publishedSource?->getAttribute('colors') ?? self::defaultColors(),
                'copy' => $publishedSource?->getAttribute('copy') ?? self::defaultCopy(),
                'layout' => $publishedSource?->getAttribute('layout') ?? BannerConfig::LAYOUT_MODAL,
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

            $nextVersion = (int) BannerConfig::query()
                ->where('domain_id', $lockedDomain->getKey())
                ->max('version') + 1;

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
                'published_at' => now(),
                'created_by_user_id' => $user->getKey(),
            ]);

            // Clone a fresh draft for further edits.
            BannerConfig::create([
                'domain_id' => $lockedDomain->getKey(),
                'version' => 0,
                'is_draft' => true,
                'is_published' => false,
                'colors' => $draft->getAttribute('colors'),
                'copy' => $draft->getAttribute('copy'),
                'layout' => $draft->getAttribute('layout'),
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
