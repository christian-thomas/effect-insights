<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CookieCategory;
use App\Enums\CookieImportStatus;
use App\Enums\CookieSource;
use App\Models\Cookie;
use App\Models\CookieImport;
use App\Services\Cookies\CookieImportPromoter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CookieImportPromoterTest extends TestCase
{
    use RefreshDatabase;

    private function staged(): CookieImport
    {
        return CookieImport::create([
            'name_pattern' => '_ga_*',
            'vendor' => 'Google',
            'vendor_domain' => '.google.com',
            'category' => CookieCategory::Statistics,
            'purpose' => 'GA4 property-scoped session identifier',
            'expiry_hint' => '2 years',
            'source' => CookieSource::OpenCookieDatabase,
            'source_version' => '2026-05-23',
            'status' => CookieImportStatus::Pending,
        ]);
    }

    #[Test]
    public function promote_moves_the_pending_row_into_canonical_and_flips_status(): void
    {
        $import = $this->staged();
        $promoter = $this->app->make(CookieImportPromoter::class);

        $cookie = $promoter->promote($import);

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('_ga_*', $cookie->getAttribute('name_pattern'));
        $this->assertSame(CookieCategory::Statistics, $cookie->getAttribute('category'));

        $import->refresh();
        $this->assertSame(CookieImportStatus::Promoted, $import->getAttribute('status'));
        $this->assertSame($cookie->getKey(), $import->getAttribute('promoted_cookie_id'));
        $this->assertNotNull($import->getAttribute('reviewed_at'));
    }

    #[Test]
    public function promote_is_idempotent_when_the_canonical_row_already_exists(): void
    {
        $import = $this->staged();
        $promoter = $this->app->make(CookieImportPromoter::class);

        $first = $promoter->promote($import);
        $second = $promoter->promote($import->refresh());

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, Cookie::query()->count());
    }

    #[Test]
    public function reject_flips_status_without_writing_a_canonical_row(): void
    {
        $import = $this->staged();
        $promoter = $this->app->make(CookieImportPromoter::class);

        $promoter->reject($import);

        $this->assertSame(0, Cookie::query()->count());
        $import->refresh();
        $this->assertSame(CookieImportStatus::Rejected, $import->getAttribute('status'));
        $this->assertNotNull($import->getAttribute('reviewed_at'));
    }
}
