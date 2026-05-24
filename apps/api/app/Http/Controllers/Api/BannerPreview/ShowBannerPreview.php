<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\BannerPreview;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\BannerConfigService;
use App\Support\BannerConfigPayloadBuilder;
use Illuminate\Http\Response;

/**
 * Returns a self-contained HTML page the admin embeds in an iframe as a
 * live preview of the current draft banner config. Bypasses Cloudflare
 * KV entirely — cmp.js reads the config inline via `data-cmp-config`.
 *
 * cmp.js's preview mode (data-cmp-preview) skips the cookie short-circuit
 * and the /consent POST, so every refresh re-renders the banner and clicks
 * have no side-effects.
 */
class ShowBannerPreview extends Controller
{
    public function __invoke(
        Domain $domain,
        BannerConfigService $service,
        BannerConfigPayloadBuilder $payloadBuilder,
    ): Response {
        $draft = $service->getOrCreateDraft($domain);
        $payload = $payloadBuilder->buildPreview($draft, $domain);

        $cdnUrl = rtrim((string) config('services.banner.cdn_url', 'https://assets.effectinsight.com'), '/');
        $configJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $html = $this->renderHtml($cdnUrl, (string) $domain->getAttribute('public_key'), $configJson);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // Previews must never be cached — every iframe load should hit
            // the freshest draft.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            // The iframe is embedded by /domains/[id]/banner.vue on the same
            // origin, so we can be strict.
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    private function renderHtml(string $cdnUrl, string $publicKey, string $configJson): string
    {
        // Escape any single-quotes the JSON might contain so the
        // data-cmp-config attribute stays parseable.
        $escapedConfig = htmlspecialchars($configJson, ENT_QUOTES, 'UTF-8');
        $escapedKey = htmlspecialchars($publicKey, ENT_QUOTES, 'UTF-8');
        $escapedCdn = htmlspecialchars($cdnUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!doctype html>
        <html lang="en">
          <head>
            <meta charset="utf-8" />
            <title>Banner preview</title>
            <style>
              html, body { margin: 0; padding: 0; height: 100%; background: #f8fafc; }
              .preview-note {
                position: fixed; top: 8px; left: 8px;
                padding: 4px 8px; font: 12px system-ui, sans-serif;
                background: rgba(15, 23, 42, 0.7); color: #fff;
                border-radius: 4px; pointer-events: none;
              }
            </style>
          </head>
          <body>
            <div class="preview-note">Preview — clicks won't be recorded</div>
            <script
              src="{$escapedCdn}/cmp.js"
              data-domain-id="{$escapedKey}"
              data-cmp-base="{$escapedCdn}"
              data-cmp-preview
              data-cmp-config="{$escapedConfig}"
              async
            ></script>
          </body>
        </html>
        HTML;
    }
}
