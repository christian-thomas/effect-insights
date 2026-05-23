<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CookieCategory;
use App\Enums\CookieImportStatus;
use App\Enums\CookieSource;
use App\Models\CookieImport;
use App\Services\Cookies\OpenCookieDatabaseImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenCookieDatabaseImporterTest extends TestCase
{
    use RefreshDatabase;

    private function csv(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/open-cookie-database-sample.csv'));
    }

    private function importer(): OpenCookieDatabaseImporter
    {
        return $this->app->make(OpenCookieDatabaseImporter::class);
    }

    #[Test]
    public function maps_ocd_rows_into_pending_cookie_imports(): void
    {
        $result = $this->importer()->import($this->csv(), '2026-05-23');

        $this->assertSame(4, $result->created);
        $this->assertSame(2, $result->skipped); // unknown category + missing name
        $this->assertSame(0, $result->existing);

        $this->assertSame(4, CookieImport::query()->count());
        $this->assertTrue(CookieImport::query()
            ->where('name_pattern', '_ga')
            ->where('source', CookieSource::OpenCookieDatabase)
            ->where('source_version', '2026-05-23')
            ->exists());
    }

    #[Test]
    public function wildcard_flag_expands_the_name_with_a_suffix_asterisk(): void
    {
        $this->importer()->import($this->csv(), '2026-05-23');

        $this->assertTrue(CookieImport::query()->where('name_pattern', '_ga_*')->exists());
        $this->assertFalse(CookieImport::query()->where('name_pattern', '_ga_')->exists());
    }

    #[Test]
    public function maps_ocd_categories_to_our_cookie_category_enum(): void
    {
        $this->importer()->import($this->csv(), '2026-05-23');

        $ga = CookieImport::query()->where('name_pattern', '_ga')->firstOrFail();
        $fbp = CookieImport::query()->where('name_pattern', '_fbp')->firstOrFail();
        $stripe = CookieImport::query()->where('name_pattern', '__stripe_mid')->firstOrFail();

        $this->assertSame(CookieCategory::Statistics, $ga->getAttribute('category'));
        $this->assertSame(CookieCategory::Marketing, $fbp->getAttribute('category'));
        $this->assertSame(CookieCategory::Necessary, $stripe->getAttribute('category'));
    }

    #[Test]
    public function rerunning_the_same_version_is_idempotent(): void
    {
        $first = $this->importer()->import($this->csv(), '2026-05-23');
        $second = $this->importer()->import($this->csv(), '2026-05-23');

        $this->assertSame(4, $first->created);
        $this->assertSame(0, $second->created);
        $this->assertSame(4, $second->existing);
        $this->assertSame(4, CookieImport::query()->count());
    }

    #[Test]
    public function a_newer_source_version_creates_separate_staging_rows(): void
    {
        $this->importer()->import($this->csv(), '2026-05-23');
        $second = $this->importer()->import($this->csv(), '2026-06-01');

        $this->assertSame(4, $second->created);
        $this->assertSame(8, CookieImport::query()->count());
    }

    #[Test]
    public function new_imports_default_to_pending_status(): void
    {
        $this->importer()->import($this->csv(), '2026-05-23');

        $this->assertSame(
            4,
            CookieImport::query()->where('status', CookieImportStatus::Pending)->count(),
        );
    }
}
