<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AccessibilityFindings;

use App\Http\Controllers\Controller;
use App\Models\AccessibilityFinding;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the element screenshot stored against a finding. Route model
 * binding on {accessibilityFinding} inherits the BelongsToOrganisation
 * global scope, so a row belonging to another tenant resolves to a 404
 * before the action body runs.
 *
 * Bytes live on the `a11y_screenshots` disk (local in dev, R2 in
 * prod). The Cache-Control header keeps the admin's <img> from
 * re-fetching on every render while still leaving the response private
 * to the authenticated session.
 */
class ShowFindingScreenshot extends Controller
{
    public function __invoke(AccessibilityFinding $accessibilityFinding): StreamedResponse
    {
        $key = $accessibilityFinding->getAttribute('screenshot_key');

        if (! is_string($key) || $key === '') {
            abort(Response::HTTP_NOT_FOUND);
        }

        $disk = Storage::disk('a11y_screenshots');

        if (! $disk->exists($key)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $disk->response($key, headers: [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
