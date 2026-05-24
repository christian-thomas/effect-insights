<?php

declare(strict_types=1);

namespace App\Services\Scanner;

use App\Models\Domain;
use Illuminate\Http\Client\Factory as HttpFactory;
use SimpleXMLElement;
use Throwable;

/**
 * Discovers URLs to scan for a domain. Pipeline:
 *   1. Fetch /sitemap.xml on the domain.
 *   2. If that fails, fetch /robots.txt and recurse on any
 *      `Sitemap:` directives it lists.
 *   3. Parse `<sitemapindex>` (recurse into child sitemaps, capped at
 *      MAX_SITEMAP_INDEX_DEPTH) and `<urlset>`.
 *   4. Same-host filter: drop URLs whose host isn't the domain or its
 *      apex — keeps scans honest, no third-party drift.
 *   5. Dedupe (case-fold host, drop trailing slash for non-root paths).
 *   6. Cap at MAX_URLS. Always includes the apex URL at index 0; falls
 *      back to apex-only if nothing else is discoverable.
 *
 * Stateless; injected as a singleton.
 */
final readonly class SitemapDiscoveryService
{
    private const int MAX_URLS = 150;

    private const int MAX_SITEMAP_INDEX_DEPTH = 5;

    private const int HTTP_TIMEOUT_SECONDS = 10;

    public function __construct(
        private HttpFactory $http,
    ) {}

    /**
     * @return array<int, string>
     */
    public function discover(Domain $domain): array
    {
        $fqdn = (string) $domain->getAttribute('fqdn');
        $apexUrl = sprintf('https://%s/', $fqdn);
        $allowedHosts = $this->allowedHosts($fqdn, (string) $domain->getAttribute('apex_domain'));

        $sitemapUrls = $this->locateSitemaps($fqdn);

        $discovered = [];
        foreach ($sitemapUrls as $sitemapUrl) {
            $discovered = array_merge($discovered, $this->parseSitemap($sitemapUrl, $allowedHosts, 0));
            if (count($discovered) >= self::MAX_URLS) {
                break;
            }
        }

        $deduped = $this->dedupe(array_merge([$apexUrl], $discovered));

        return array_slice($deduped, 0, self::MAX_URLS);
    }

    /**
     * @return array<int, string>
     */
    private function locateSitemaps(string $fqdn): array
    {
        $candidates = [sprintf('https://%s/sitemap.xml', $fqdn)];

        $robotsBody = $this->fetchBody(sprintf('https://%s/robots.txt', $fqdn));
        if ($robotsBody !== null) {
            preg_match_all('/Sitemap:\s*(\S+)/i', $robotsBody, $matches);
            foreach ($matches[1] as $match) {
                $candidates[] = trim($match);
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  array<int, string>  $allowedHosts
     * @return array<int, string>
     */
    private function parseSitemap(string $sitemapUrl, array $allowedHosts, int $depth): array
    {
        if ($depth >= self::MAX_SITEMAP_INDEX_DEPTH) {
            return [];
        }

        $body = $this->fetchBody($sitemapUrl);
        if ($body === null) {
            return [];
        }

        try {
            $xml = new SimpleXMLElement($body);
        } catch (Throwable) {
            return [];
        }

        if ($xml->getName() === 'sitemapindex') {
            $childUrls = [];
            foreach ($xml->sitemap as $child) {
                $loc = trim((string) $child->loc);
                if ($loc !== '') {
                    $childUrls = array_merge($childUrls, $this->parseSitemap($loc, $allowedHosts, $depth + 1));
                }
                if (count($childUrls) >= self::MAX_URLS) {
                    break;
                }
            }

            return $childUrls;
        }

        $urls = [];
        foreach ($xml->url as $entry) {
            $loc = trim((string) $entry->loc);
            if ($loc === '') {
                continue;
            }
            if (! $this->hostIsAllowed($loc, $allowedHosts)) {
                continue;
            }
            $urls[] = $loc;
        }

        return $urls;
    }

    private function fetchBody(string $url): ?string
    {
        try {
            $response = $this->http->timeout(self::HTTP_TIMEOUT_SECONDS)->get($url);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? (string) $response->body() : null;
    }

    /**
     * @return array<int, string>
     */
    private function allowedHosts(string $fqdn, string $apex): array
    {
        return array_values(array_unique(array_filter([
            strtolower($fqdn),
            strtolower($apex),
        ])));
    }

    /**
     * @param  array<int, string>  $allowedHosts
     */
    private function hostIsAllowed(string $url, array $allowedHosts): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach ($allowedHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, sprintf('.%s', $allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function dedupe(array $urls): array
    {
        $seen = [];
        $out = [];
        foreach ($urls as $url) {
            $normalised = $this->normalise($url);
            if (isset($seen[$normalised])) {
                continue;
            }
            $seen[$normalised] = true;
            $out[] = $url;
        }

        return $out;
    }

    private function normalise(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return strtolower($url);
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return sprintf('%s://%s%s%s', $scheme, $host, $path, $query);
    }
}
