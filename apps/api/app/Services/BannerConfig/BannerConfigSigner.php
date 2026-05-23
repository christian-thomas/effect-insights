<?php

declare(strict_types=1);

namespace App\Services\BannerConfig;

/**
 * Signs / verifies banner config payloads with HMAC-SHA-256.
 *
 * The same secret lives on the Laravel side (BANNER_CONFIG_SIGNING_KEY env)
 * and on the edge config worker (`wrangler secret put BANNER_CONFIG_SIGNING_KEY`).
 * Laravel signs on publish → KV; the worker re-computes on read and
 * refuses to serve any payload whose signature doesn't match. cmp.js
 * is downstream of the worker so it doesn't need the key — the worker
 * is the trust boundary.
 *
 * The signed bytes are a canonical JSON serialisation of the payload
 * with the `signature` field stripped — keys sorted recursively, no
 * whitespace, JSON_UNESCAPED_SLASHES so // doesn't get escaped into
 * \/. Same serialisation lives in the worker (apps/edge/src/config-worker.ts).
 */
final readonly class BannerConfigSigner
{
    public function __construct(
        private string $key,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload): string
    {
        $canonical = $this->canonicalise($this->withoutSignature($payload));

        return hash_hmac('sha256', $canonical, $this->key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verify(array $payload, string $signature): bool
    {
        if ($signature === '' || $signature === 'unsigned') {
            return false;
        }

        return hash_equals($this->sign($payload), $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutSignature(array $payload): array
    {
        unset($payload['signature']);

        return $payload;
    }

    /**
     * Recursively ksort + json_encode without whitespace. Keeps the
     * signed bytes stable across PHP / JS / re-serialisations.
     *
     * @param  array<string, mixed>|mixed  $value
     */
    private function canonicalise(mixed $value): string
    {
        return json_encode(
            $this->normalise($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = $this->normalise($item);
        }

        if (! $isList) {
            ksort($result);
        }

        return $result;
    }
}
