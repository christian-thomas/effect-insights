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
 *     src="https://api.effectinsight.com/cookie-policy/pk_xxx.html"
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
            return new Response('Cookie policy not found.', HttpFoundationResponse::HTTP_NOT_FOUND, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $html = view('cookie-policies.public', [
            'policy' => $this->builder->build($domain),
        ])->render();

        return new Response($html, HttpFoundationResponse::HTTP_OK, [
            'Content-Type' => 'text/html; charset=utf-8',
            // Cache moderately. New scan ⇒ ≤1 hour stale before clients see it.
            'Cache-Control' => 'public, max-age=3600, stale-while-revalidate=86400',
            // Iframe-friendly: hosts will embed this on their own origin.
            'X-Frame-Options' => 'ALLOWALL',
        ]);
    }
}
