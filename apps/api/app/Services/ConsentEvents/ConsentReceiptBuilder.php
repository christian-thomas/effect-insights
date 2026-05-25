<?php

declare(strict_types=1);

namespace App\Services\ConsentEvents;

use App\Enums\BannerLayout;
use App\Enums\ConsentEventType;
use App\Exceptions\NoConsentEventsForVisitor;
use App\Models\BannerConfig;
use App\Models\ConsentEvent;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Assembles regulator-ready consent receipts and stamps them with an
 * HMAC-SHA-256 signature via {@see ConsentReceiptSigner}.
 *
 * Two shapes:
 *  - forEvent()   — one event, one receipt
 *  - forVisitor() — every event for one anonymous_id on one domain,
 *                   packaged as a single bundle receipt
 *
 * Both embed a snapshot of the banner config that was active at the
 * recorded `banner_config_version`. If the historical row can't be
 * located (which would mean someone deleted an append-only history
 * row), we fall back to the currently-published row and stamp
 * `bannerConfigSnapshotNote = "current_published"` so the receipt is
 * still self-describing.
 */
final readonly class ConsentReceiptBuilder
{
    private const string FORMAT_VERSION = '1';

    public function __construct(
        private ConsentReceiptSigner $signer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forEvent(ConsentEvent $event, ?User $generatedBy = null): array
    {
        /** @var Domain $domain */
        $domain = $event->domain()->withoutGlobalScope('organisation')->firstOrFail();

        $payload = [
            'formatVersion' => self::FORMAT_VERSION,
            'mode' => 'event',
            'generatedAt' => Date::now()->toIso8601String(),
            'generatedBy' => $generatedBy?->getKey(),
            'organisation' => $this->organisationSnapshot($domain),
            'domain' => $this->domainSnapshot($domain),
            'event' => $this->eventSnapshot($event),
            'bannerConfig' => $this->bannerConfigSnapshot($domain, (int) $event->getAttribute('banner_config_version')),
            'signatureAlgorithm' => 'HMAC-SHA-256',
        ];

        $payload['signature'] = $this->signer->sign($payload);

        return $payload;
    }

    /**
     * @param  iterable<ConsentEvent>  $events  Already ordered by occurred_at ascending.
     * @return array<string, mixed>
     */
    public function forVisitor(string $anonymousId, Domain $domain, iterable $events, ?User $generatedBy = null): array
    {
        $eventSnapshots = [];
        $versions = [];

        foreach ($events as $event) {
            $eventSnapshots[] = $this->eventSnapshot($event);
            $versions[(int) $event->getAttribute('banner_config_version')] = true;
        }

        $bannerConfigs = [];
        foreach (array_keys($versions) as $version) {
            $bannerConfigs[(string) $version] = $this->bannerConfigSnapshot($domain, $version);
        }

        $payload = [
            'formatVersion' => self::FORMAT_VERSION,
            'mode' => 'visitor',
            'generatedAt' => Date::now()->toIso8601String(),
            'generatedBy' => $generatedBy?->getKey(),
            'organisation' => $this->organisationSnapshot($domain),
            'domain' => $this->domainSnapshot($domain),
            'anonymousId' => $anonymousId,
            'eventCount' => count($eventSnapshots),
            'events' => $eventSnapshots,
            'bannerConfigs' => $bannerConfigs,
            'signatureAlgorithm' => 'HMAC-SHA-256',
        ];

        $payload['signature'] = $this->signer->sign($payload);

        return $payload;
    }

    /**
     * Convenience that pulls the events for an anonymous_id + domain
     * pair, then delegates to {@see self::forVisitor}. Throws
     * {@see NoConsentEventsForVisitor} (404) when nothing's on record.
     *
     * @return array<string, mixed>
     */
    public function forVisitorByDomain(
        string $anonymousId,
        Domain $domain,
        ?User $generatedBy = null,
    ): array {
        $events = ConsentEvent::query()
            ->where('anonymous_id', $anonymousId)
            ->where('domain_id', $domain->getKey())
            ->oldest('occurred_at')
            ->get();

        if ($events->isEmpty()) {
            throw new NoConsentEventsForVisitor;
        }

        return $this->forVisitor($anonymousId, $domain, $events, $generatedBy);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventSnapshot(ConsentEvent $event): array
    {
        /** @var ConsentEventType $eventType */
        $eventType = $event->getAttribute('event_type');
        $occurredAt = $event->getAttribute('occurred_at');

        return [
            'id' => $event->getKey(),
            'anonymousId' => $event->getAttribute('anonymous_id'),
            'eventType' => $eventType->value,
            'occurredAt' => $occurredAt?->toIso8601String(),
            'categories' => $event->getAttribute('categories'),
            'gcmSignals' => $event->getAttribute('gcm_signals'),
            'countryCode' => $event->getAttribute('country_code'),
            'userAgentHash' => $event->getAttribute('user_agent_hash'),
            'ipHash' => $event->getAttribute('ip_hash'),
            'bannerConfigVersion' => (int) $event->getAttribute('banner_config_version'),
            'bannerTextHash' => $event->getAttribute('banner_text_hash'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function domainSnapshot(Domain $domain): array
    {
        return [
            'id' => $domain->getKey(),
            'fqdn' => $domain->getAttribute('fqdn'),
            'publicKey' => $domain->getAttribute('public_key'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organisationSnapshot(Domain $domain): array
    {
        $organisation = $domain->organisation()->withoutGlobalScope('organisation')->first();

        return [
            'id' => $organisation?->getKey(),
            'name' => $organisation?->getAttribute('name'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bannerConfigSnapshot(Domain $domain, int $version): array
    {
        $historical = BannerConfig::query()
            ->withoutGlobalScope('organisation')
            ->where('domain_id', $domain->getKey())
            ->where('version', $version)
            ->first();

        if ($historical !== null) {
            return $this->mapBannerConfig($historical, 'historical');
        }

        $currentPublished = BannerConfig::query()
            ->withoutGlobalScope('organisation')
            ->where('domain_id', $domain->getKey())
            ->where('is_published', true)
            ->latest('version')
            ->first();

        if ($currentPublished !== null) {
            return $this->mapBannerConfig($currentPublished, 'current_published');
        }

        return [
            'version' => $version,
            'snapshotNote' => 'unavailable',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBannerConfig(BannerConfig $bannerConfig, string $note): array
    {
        /** @var BannerLayout $layout */
        $layout = $bannerConfig->getAttribute('layout');
        $publishedAt = $bannerConfig->getAttribute('published_at');

        return [
            'snapshotNote' => $note,
            'version' => (int) $bannerConfig->getAttribute('version'),
            'materialVersion' => (int) $bannerConfig->getAttribute('material_version'),
            'localeDefault' => (string) $bannerConfig->getAttribute('locale_default'),
            'localesEnabled' => (array) $bannerConfig->getAttribute('locales_enabled'),
            'layout' => $layout->value,
            'colors' => (array) $bannerConfig->getAttribute('colors'),
            'copy' => (array) $bannerConfig->getAttribute('copy'),
            'geoRules' => (array) $bannerConfig->getAttribute('geo_rules'),
            'gcmDefaults' => (array) $bannerConfig->getAttribute('gcm_defaults'),
            'behaviour' => (array) $bannerConfig->getAttribute('behaviour'),
            'publishedAt' => $publishedAt?->toIso8601String(),
        ];
    }
}
