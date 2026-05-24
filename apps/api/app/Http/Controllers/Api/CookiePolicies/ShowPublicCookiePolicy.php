<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\CookiePolicies;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Cookies\CookiePolicyBuilder;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

/**
 * Public, unauthenticated HTML render of the cookie policy. Looked up
 * by the domain's public_key so embedders never need to know the
 * internal UUID and there's no tenant info to leak.
 *
 * Designed to be dropped into an iframe on the client's privacy page:
 *
 *   <iframe
 *     src="https://api.effectcmp.com/cookie-policy/pk_xxx.html"
 *     title="Cookies in use"
 *     style="width:100%; min-height:600px; border:0;"
 *   ></iframe>
 *
 * The HTML is self-contained (inline styles, no external requests) so
 * it renders inside strict-CSP host pages without nonce wiring.
 */
class ShowPublicCookiePolicy extends Controller
{
    public function __construct(
        private readonly CookiePolicyBuilder $builder,
    ) {}

    public function __invoke(string $publicKey): Response
    {
        /** @var Domain|null $domain */
        $domain = Domain::query()
            ->withoutGlobalScope('organisation')
            ->where('public_key', $publicKey)
            ->first();

        if ($domain === null) {
            return response('Cookie policy not found.', HttpFoundationResponse::HTTP_NOT_FOUND, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $policy = $this->builder->build($domain);
        $html = $this->renderHtml($policy);

        return response($html, HttpFoundationResponse::HTTP_OK, [
            'Content-Type' => 'text/html; charset=utf-8',
            // Cache moderately. New scan ⇒ ≤1 hour stale before clients see it.
            'Cache-Control' => 'public, max-age=3600, stale-while-revalidate=86400',
            // Iframe-friendly: hosts will embed this on their own origin.
            'X-Frame-Options' => 'ALLOWALL',
        ]);
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function renderHtml(array $policy): string
    {
        $domain = $policy['domain'];
        $generatedAt = htmlspecialchars((string) $policy['generatedAt'], ENT_QUOTES, 'UTF-8');
        $fqdn = htmlspecialchars((string) $domain['fqdn'], ENT_QUOTES, 'UTF-8');

        $body = '';
        $categories = (array) $policy['categories'];

        if ($categories === []) {
            $body = '<p class="empty">No cookies have been detected yet. This page will populate after the first scan completes.</p>';
        } else {
            foreach ($categories as $bucket) {
                $body .= $this->renderCategoryBlock($bucket);
            }
        }

        return <<<HTML
        <!doctype html>
        <html lang="en">
          <head>
            <meta charset="utf-8" />
            <title>Cookies in use — {$fqdn}</title>
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <style>
              :root { color-scheme: light dark; }
              body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                color: #111827; background: #ffffff;
                margin: 0; padding: 1.5rem;
                line-height: 1.5;
              }
              @media (prefers-color-scheme: dark) {
                body { color: #f3f4f6; background: #0b0f1a; }
                table { border-color: #1f2937; }
                thead { background: #111827; }
                tbody tr:nth-child(even) { background: #0f172a; }
                .meta { color: #9ca3af; }
              }
              h1 { font-size: 1.4rem; margin: 0 0 0.25rem; }
              h2 { font-size: 1.1rem; margin: 1.75rem 0 0.25rem; }
              .meta { color: #6b7280; font-size: 0.85rem; }
              .desc { color: #4b5563; font-size: 0.9rem; margin: 0.25rem 0 0.75rem; }
              .empty {
                padding: 1rem; border: 1px dashed #d1d5db; border-radius: 6px;
                color: #6b7280; font-size: 0.95rem;
              }
              table {
                width: 100%; border-collapse: collapse;
                border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;
                font-size: 0.9rem;
              }
              thead { background: #f3f4f6; }
              th, td { padding: 0.5rem 0.6rem; text-align: left; vertical-align: top; }
              th { font-weight: 600; }
              tbody tr:nth-child(even) { background: #f9fafb; }
              code { font-family: "SFMono-Regular", Menlo, monospace; font-size: 0.85rem; }
              .party-third { color: #b45309; font-weight: 600; }
              .party-first { color: #047857; font-weight: 600; }
            </style>
          </head>
          <body>
            <h1>Cookies in use on {$fqdn}</h1>
            <p class="meta">Generated automatically from the latest site scan · {$generatedAt}</p>
            {$body}
          </body>
        </html>
        HTML;
    }

    /**
     * @param  array<string, mixed>  $bucket
     */
    private function renderCategoryBlock(array $bucket): string
    {
        $label = htmlspecialchars((string) $bucket['label'], ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars((string) $bucket['description'], ENT_QUOTES, 'UTF-8');
        $cookies = (array) $bucket['cookies'];

        $rows = '';
        foreach ($cookies as $cookie) {
            $name = htmlspecialchars((string) $cookie['name'], ENT_QUOTES, 'UTF-8');
            $domainSetOn = htmlspecialchars((string) ($cookie['domainSetOn'] ?? ''), ENT_QUOTES, 'UTF-8');
            $purpose = htmlspecialchars((string) ($cookie['purpose'] ?? ''), ENT_QUOTES, 'UTF-8');
            $partyValue = (string) ($cookie['party'] ?? '');
            $partyClass = $partyValue === 'third' ? 'party-third' : 'party-first';
            $partyLabel = htmlspecialchars(ucfirst($partyValue ?: 'first'), ENT_QUOTES, 'UTF-8');

            $rows .= <<<ROW
            <tr>
              <td><code>{$name}</code></td>
              <td class="{$partyClass}">{$partyLabel}-party</td>
              <td>{$domainSetOn}</td>
              <td>{$purpose}</td>
            </tr>
            ROW;
        }

        return <<<BLOCK
        <h2>{$label}</h2>
        <p class="desc">{$description}</p>
        <table>
          <thead>
            <tr>
              <th>Cookie name</th>
              <th>Party</th>
              <th>Domain</th>
              <th>Purpose</th>
            </tr>
          </thead>
          <tbody>
            {$rows}
          </tbody>
        </table>
        BLOCK;
    }
}
