<?php

declare(strict_types=1);

namespace App\Services\Cookies;

use App\Enums\CookieCategory;
use App\Enums\FindingType;
use App\Enums\ScanStatus;
use App\Models\Domain;
use App\Models\Scan;
use App\Models\ScanFinding;
use Carbon\CarbonInterface;

/**
 * Assembles the cookie-policy data for a given Domain from the latest
 * completed scan + canonical cookie DB + per-org overrides.
 *
 * Output is a category-grouped, name-deduplicated list shaped for both
 * the public HTML render and the JSON embed snippet. Cookies surface
 * even when the resolver couldn't categorise them — they end up in the
 * 'unknown' bucket so legal can see them rather than have them silently
 * disappear from the policy.
 *
 * Empty when the domain has never been scanned — the controller is
 * responsible for the messaging.
 */
final class CookiePolicyBuilder
{
    /**
     * @return array{
     *   domain: array{fqdn: string, publicKey: string},
     *   generatedAt: string,
     *   scan: null|array{id: string, finishedAt: string},
     *   categories: array<int, array{
     *     category: string,
     *     label: string,
     *     description: string,
     *     cookies: array<int, array{
     *       name: string,
     *       party: string,
     *       domainSetOn: string|null,
     *       purpose: string|null,
     *       firstSeenUrl: string|null,
     *     }>
     *   }>
     * }
     */
    public function build(Domain $domain): array
    {
        $now = now();

        /** @var Scan|null $latestScan */
        $latestScan = Scan::query()
            ->where('domain_id', $domain->getKey())
            ->where('status', ScanStatus::Completed)
            ->orderByDesc('finished_at')
            ->first();

        $categoriesPayload = [];

        if ($latestScan !== null) {
            /** @var array<string, array<int, ScanFinding>> $byCategory */
            $byCategory = [];

            $findings = ScanFinding::query()
                ->where('scan_id', $latestScan->getKey())
                ->where('type', FindingType::Cookie)
                ->orderBy('name')
                ->get();

            /** @var array<string, true> $seen */
            $seen = [];

            foreach ($findings as $finding) {
                $name = (string) $finding->getAttribute('name');
                if ($name === '' || isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;

                $category = $finding->getAttribute('category');
                $key = $category instanceof CookieCategory ? $category->value : 'unknown';
                $byCategory[$key] ??= [];
                $byCategory[$key][] = $finding;
            }

            foreach ($this->orderedCategoryKeys() as $key) {
                $cookies = $byCategory[$key] ?? [];
                if ($cookies === []) {
                    continue;
                }

                $categoriesPayload[] = [
                    'category' => $key,
                    'label' => $this->labelFor($key),
                    'description' => $this->descriptionFor($key),
                    'cookies' => array_map(fn (ScanFinding $finding): array => [
                        'name' => (string) $finding->getAttribute('name'),
                        'party' => (string) $finding->getAttribute('party')?->value,
                        'domainSetOn' => $finding->getAttribute('domain_set_on'),
                        'purpose' => $finding->getAttribute('purpose_text'),
                        'firstSeenUrl' => $finding->getAttribute('first_seen_url'),
                    ], $cookies),
                ];
            }
        }

        return [
            'domain' => [
                'fqdn' => (string) $domain->getAttribute('fqdn'),
                'publicKey' => (string) $domain->getAttribute('public_key'),
            ],
            'generatedAt' => $now->toIso8601String(),
            'scan' => $latestScan === null ? null : [
                'id' => (string) $latestScan->getKey(),
                'finishedAt' => $this->iso($latestScan->getAttribute('finished_at')),
            ],
            'categories' => $categoriesPayload,
        ];
    }

    /**
     * @return list<string>
     */
    private function orderedCategoryKeys(): array
    {
        // Necessary first (regulators expect it to lead), then the rest
        // in a familiar order, then the unknown bucket so reviewers can
        // see uncategorised cookies at the bottom rather than hidden.
        return [
            CookieCategory::Necessary->value,
            CookieCategory::Preferences->value,
            CookieCategory::Statistics->value,
            CookieCategory::Marketing->value,
            'unknown',
        ];
    }

    private function labelFor(string $key): string
    {
        return match ($key) {
            CookieCategory::Necessary->value => 'Strictly necessary',
            CookieCategory::Preferences->value => 'Preferences',
            CookieCategory::Statistics->value => 'Statistics',
            CookieCategory::Marketing->value => 'Marketing',
            default => 'Unknown — pending review',
        };
    }

    private function descriptionFor(string $key): string
    {
        return match ($key) {
            CookieCategory::Necessary->value => 'Required for the site to function. Cannot be disabled.',
            CookieCategory::Preferences->value => 'Remember choices the visitor makes (language, region, etc).',
            CookieCategory::Statistics->value => 'Anonymous analytics about how the site is used.',
            CookieCategory::Marketing->value => 'Personalised advertising and tracking.',
            default => 'Detected by the latest scan but not yet categorised. Update the canonical cookie database or add a per-org override to remove from this bucket.',
        };
    }

    private function iso(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }
}
