<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ConsentEvents\ConsentReceiptSigner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConsentReceiptSignerTest extends TestCase
{
    private function signer(string $key = 'unit-test-key'): ConsentReceiptSigner
    {
        return new ConsentReceiptSigner($key);
    }

    #[Test]
    public function sign_produces_a_stable_hex_hmac(): void
    {
        $first = $this->signer()->sign(['eventId' => 'evt_a', 'version' => 1]);
        $second = $this->signer()->sign(['eventId' => 'evt_a', 'version' => 1]);

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
    }

    #[Test]
    public function key_order_does_not_affect_the_signature(): void
    {
        $first = $this->signer()->sign([
            'eventId' => 'evt_a',
            'version' => 1,
            'categories' => ['marketing' => false, 'necessary' => true],
        ]);
        $second = $this->signer()->sign([
            'categories' => ['necessary' => true, 'marketing' => false],
            'version' => 1,
            'eventId' => 'evt_a',
        ]);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function list_order_does_affect_the_signature(): void
    {
        $first = $this->signer()->sign(['events' => ['evt_a', 'evt_b']]);
        $second = $this->signer()->sign(['events' => ['evt_b', 'evt_a']]);

        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function signature_and_signature_algorithm_fields_are_ignored_when_signing(): void
    {
        $first = $this->signer()->sign(['version' => 1]);
        $second = $this->signer()->sign([
            'version' => 1,
            'signature' => 'whatever',
            'signatureAlgorithm' => 'HMAC-SHA-256',
        ]);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function verify_returns_true_for_a_signature_we_produced(): void
    {
        $signer = $this->signer();
        $payload = ['version' => 1, 'eventId' => 'evt_x'];

        $signature = $signer->sign($payload);

        $this->assertTrue($signer->verify($payload, $signature));
    }

    #[Test]
    public function verify_returns_false_for_a_tampered_payload(): void
    {
        $signer = $this->signer();
        $payload = ['eventId' => 'evt_x', 'categories' => ['statistics' => true]];

        $signature = $signer->sign($payload);
        $tampered = ['eventId' => 'evt_x', 'categories' => ['statistics' => false]];

        $this->assertFalse($signer->verify($tampered, $signature));
    }

    #[Test]
    public function verify_returns_false_for_a_different_key(): void
    {
        $payload = ['version' => 1];
        $signature = $this->signer('key-A')->sign($payload);

        $this->assertFalse($this->signer('key-B')->verify($payload, $signature));
    }

    #[Test]
    public function verify_rejects_empty_and_unsigned_markers(): void
    {
        $this->assertFalse($this->signer()->verify(['version' => 1], 'unsigned'));
        $this->assertFalse($this->signer()->verify(['version' => 1], ''));
    }
}
