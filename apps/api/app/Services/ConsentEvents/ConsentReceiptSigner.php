<?php

declare(strict_types=1);

namespace App\Services\ConsentEvents;

use App\Services\BannerConfig\BannerConfigSigner;

/**
 * Signs / verifies consent receipts with HMAC-SHA-256.
 *
 * Receipts are self-contained JSON: regulators (or anyone with the same
 * CONSENT_RECEIPT_SIGNING_KEY) can re-compute the signature offline to
 * verify the document hasn't been tampered with after export.
 *
 * Structurally identical to {@see BannerConfigSigner}
 * — same canonical-JSON, key-sorted, no-whitespace serialisation. Kept as
 * a separate class with its own key so rotation has a bounded blast radius.
 */
final readonly class ConsentReceiptSigner
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
        unset($payload['signature'], $payload['signatureAlgorithm']);

        return $payload;
    }

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
