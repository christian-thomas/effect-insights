<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Guards the internal consent ingest endpoint. The edge consent worker is the
 * only caller; it sends the shared secret in X-Effect-Ingest-Secret. Any
 * request without a matching secret is rejected with 401.
 *
 * HMAC over the body would be stronger but requires synchronised clocks +
 * canonical-form serialisation between TS edge and PHP. The shared-secret
 * header is fine for v0 — the endpoint isn't exposed publicly (only the edge
 * worker has the URL) and the secret is rotateable.
 */
class VerifyConsentIngestSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.consent_ingest.secret');

        if (! is_string($expected) || $expected === '') {
            throw new HttpException(
                Response::HTTP_SERVICE_UNAVAILABLE,
                'Consent ingest is not configured on this server',
            );
        }

        $provided = (string) $request->header('X-Effect-Ingest-Secret', '');

        if (! hash_equals($expected, $provided)) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Invalid ingest secret');
        }

        return $next($request);
    }
}
