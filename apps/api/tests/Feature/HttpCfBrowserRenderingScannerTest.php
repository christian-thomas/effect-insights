<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Organisation;
use App\Services\Scanner\HttpCfBrowserRenderingScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HttpCfBrowserRenderingScannerTest extends TestCase
{
    use RefreshDatabase;

    private function scanner(): HttpCfBrowserRenderingScanner
    {
        return new HttpCfBrowserRenderingScanner(
            http: $this->app->make(Factory::class),
            workerUrl: 'https://scanner.example.test',
            secret: 'test-secret',
            timeoutSeconds: 5,
        );
    }

    private function makeDomain(): Domain
    {
        $organisation = Organisation::create([
            'name' => 'Example',
            'slug' => 'example',
        ]);

        $domain = new Domain([
            'fqdn' => 'example.test',
            'apex_domain' => 'example.test',
        ]);
        $domain->organisation_id = $organisation->getKey();
        $domain->save();

        return $domain;
    }

    #[Test]
    public function posts_urls_and_secret_header_to_the_worker(): void
    {
        Http::fake([
            'scanner.example.test/scan' => Http::response([
                'urlsDiscovered' => 1,
                'urlsScanned' => 1,
                'cookies' => [],
                'scripts' => [],
                'iframes' => [],
                'requests' => [],
            ]),
        ]);

        $this->scanner()->scan($this->makeDomain(), ['https://example.test/'], 'test-org-id', 'test-scan-id');

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://scanner.example.test/scan'
            && $request->method() === 'POST'
            && $request->header('X-Effect-Scanner-Secret')[0] === 'test-secret'
            && $request['urls'] === ['https://example.test/']
            && $request['domainFqdn'] === 'example.test'
            && $request['organisationId'] === 'test-org-id'
            && $request['scanId'] === 'test-scan-id');
    }

    #[Test]
    public function parses_the_worker_response_into_a_scanner_result(): void
    {
        Http::fake([
            'scanner.example.test/scan' => Http::response([
                'urlsDiscovered' => 2,
                'urlsScanned' => 2,
                'cookies' => [
                    [
                        'name' => '_ga_ABC',
                        'domain' => '.example.test',
                        'party' => 'first',
                        'firstSeenUrl' => 'https://example.test/',
                    ],
                ],
                'scripts' => [
                    ['name' => 'https://gtm.example.test/gtm.js', 'party' => 'first'],
                ],
                'iframes' => [],
                'requests' => [
                    ['name' => 'https://cdn.example.test/style.css', 'party' => 'first'],
                ],
            ]),
        ]);

        $result = $this->scanner()->scan($this->makeDomain(), ['https://example.test/'], 'test-org-id', 'test-scan-id');

        $this->assertSame(2, $result->urlsScanned);
        $this->assertSame('_ga_ABC', $result->cookies[0]['name']);
        $this->assertSame('first', $result->cookies[0]['party']);
        $this->assertSame('https://gtm.example.test/gtm.js', $result->scripts[0]['name']);
        $this->assertSame([], $result->iframes);
        $this->assertCount(1, $result->requests);
    }

    #[Test]
    public function throws_when_the_worker_returns_a_non_2xx(): void
    {
        Http::fake([
            'scanner.example.test/scan' => Http::response('upstream busted', 502),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Scanner worker returned 502/');

        $this->scanner()->scan($this->makeDomain(), ['https://example.test/'], 'test-org-id', 'test-scan-id');
    }

    #[Test]
    public function throws_when_no_worker_url_or_secret_is_configured(): void
    {
        $scanner = new HttpCfBrowserRenderingScanner(
            http: $this->app->make(Factory::class),
            workerUrl: '',
            secret: '',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Scanner worker is not configured/');

        $scanner->scan($this->makeDomain(), ['https://example.test/'], 'test-org-id', 'test-scan-id');
    }
}
