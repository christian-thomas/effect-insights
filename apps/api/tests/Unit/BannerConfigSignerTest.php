<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BannerConfig\BannerConfigSigner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BannerConfigSignerTest extends TestCase
{
    private function signer(string $key = 'unit-test-key'): BannerConfigSigner
    {
        return new BannerConfigSigner($key);
    }

    #[Test]
    public function sign_produces_a_stable_hex_hmac(): void
    {
        $first = $this->signer()->sign(['domainPublicKey' => 'pk_a', 'version' => 1]);
        $second = $this->signer()->sign(['domainPublicKey' => 'pk_a', 'version' => 1]);

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
    }

    #[Test]
    public function key_order_does_not_affect_the_signature(): void
    {
        $a = $this->signer()->sign([
            'domainPublicKey' => 'pk_a',
            'version' => 1,
            'colors' => ['background' => '#fff', 'primary' => '#000'],
        ]);
        $b = $this->signer()->sign([
            'colors' => ['primary' => '#000', 'background' => '#fff'],
            'version' => 1,
            'domainPublicKey' => 'pk_a',
        ]);

        $this->assertSame($a, $b);
    }

    #[Test]
    public function list_order_does_affect_the_signature(): void
    {
        $a = $this->signer()->sign(['localesEnabled' => ['en', 'fr']]);
        $b = $this->signer()->sign(['localesEnabled' => ['fr', 'en']]);

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function the_signature_field_in_the_input_is_ignored_when_signing(): void
    {
        $a = $this->signer()->sign(['version' => 1]);
        $b = $this->signer()->sign(['version' => 1, 'signature' => 'whatever']);

        $this->assertSame($a, $b);
    }

    #[Test]
    public function verify_returns_true_for_a_signature_we_produced(): void
    {
        $signer = $this->signer();
        $payload = ['version' => 1, 'domainPublicKey' => 'pk_x'];

        $signature = $signer->sign($payload);

        $this->assertTrue($signer->verify($payload, $signature));
    }

    #[Test]
    public function verify_returns_false_for_a_tampered_payload(): void
    {
        $signer = $this->signer();
        $payload = ['version' => 1, 'gcmDefaults' => ['analytics_storage' => 'denied']];

        $signature = $signer->sign($payload);
        $tampered = ['version' => 1, 'gcmDefaults' => ['analytics_storage' => 'granted']];

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
    public function verify_rejects_the_legacy_unsigned_marker(): void
    {
        $this->assertFalse($this->signer()->verify(['version' => 1], 'unsigned'));
        $this->assertFalse($this->signer()->verify(['version' => 1], ''));
    }
}
