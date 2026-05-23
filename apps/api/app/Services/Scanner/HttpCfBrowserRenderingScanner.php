<?php

declare(strict_types=1);

namespace App\Services\Scanner;

use App\Models\Domain;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Calls Cloudflare Browser Rendering's /scrape endpoint for each URL,
 * collects observed cookies/scripts/iframes/requests, and normalises
 * them into a {@see ScannerResult}.
 *
 * @todo Real implementation — for now this throws on call. The contract
 *       is in place so {@see ScanRunner} can be built + tested against
 *       {@see ArrayScannerClient}, and the HTTP integration lands as a
 *       separate piece of work.
 *
 *       Reference: https://developers.cloudflare.com/browser-rendering/rest-api/
 *
 *       Sketch of the eventual flow:
 *         POST https://api.cloudflare.com/client/v4/accounts/{id}/browser-rendering/scrape
 *           { url, elements: [...], gotoOptions: { waitUntil: 'networkidle0' } }
 *         → returns rendered DOM + network log; we parse cookies/scripts/etc out
 */
final readonly class HttpCfBrowserRenderingScanner implements ScannerClientContract
{
    public function __construct(
        private HttpFactory $http,
        private string $accountId,
        private string $apiToken,
    ) {}

    /**
     * @param  array<int, string>  $urls
     */
    #[\Override]
    public function scan(Domain $domain, array $urls): ScannerResult
    {
        throw new RuntimeException(sprintf(
            'HttpCfBrowserRenderingScanner is not implemented yet '.
            '(account=%s, token=%s, http=%s). Bind ArrayScannerClient in tests, '.
            'or wire the real CF Browser Rendering POST.',
            $this->accountId !== '' ? 'configured' : 'missing',
            $this->apiToken !== '' ? 'configured' : 'missing',
            $this->http::class,
        ));
    }
}
