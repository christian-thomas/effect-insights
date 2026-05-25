<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TenantTestCase;

class CreateCookieImportTest extends TenantTestCase
{
    private const SAMPLE_CSV = <<<CSV
ID,Vendor,Category,Cookie / Data Key name,Domain,Description,Retention period,Wildcard match
1,Google,Analytics,_ga,google.com,Used to distinguish users,2 years,0
2,Google,Analytics,_ga_,google.com,Container-specific GA4 cookie,2 years,1
3,Meta,Marketing,_fbp,facebook.com,Facebook pixel tracking cookie,3 months,0
CSV;

    /**
     * The actual upstream Open-Cookie-Database CSV header uses "Platform"
     * for the vendor column and includes extra columns we ignore.
     */
    private const REAL_OCD_CSV = <<<CSV
ID,Platform,Category,Cookie / Data Key name,Domain,Description,Retention period,Data Controller,User Privacy & GDPR Rights Portals,Wildcard match
1,Google,Analytics,_ga,google.com,Distinguishes unique users,2 years,Google LLC,https://policies.google.com/privacy,0
2,Meta,Marketing,_fbp,facebook.com,Pixel tracking,3 months,Meta,https://facebook.com/policies/cookies/,0
CSV;

    #[Test]
    public function requires_superuser(): void
    {
        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-imports', [
                'file' => UploadedFile::fake()->createWithContent('opencookies.csv', self::SAMPLE_CSV),
            ]);

        $response->assertStatus(Response::HTTP_FORBIDDEN);
        $this->assertDatabaseCount('cookie_imports', 0);
    }

    #[Test]
    public function rejects_a_missing_file(): void
    {
        $this->makeSuperuser($this->user);

        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-imports', [])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function stages_rows_into_cookie_imports_with_caller_supplied_source_version(): void
    {
        $this->makeSuperuser($this->user);

        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-imports', [
                'file' => UploadedFile::fake()->createWithContent('opencookies.csv', self::SAMPLE_CSV),
                'source_version' => '2026-05-25',
            ]);

        $response->assertStatus(Response::HTTP_CREATED)
            ->assertJsonPath('data.sourceVersion', '2026-05-25')
            ->assertJsonPath('data.created', 3)
            ->assertJsonPath('data.existing', 0)
            ->assertJsonPath('data.skipped', 0);

        $this->assertDatabaseCount('cookie_imports', 3);
        $this->assertDatabaseHas('cookie_imports', [
            'source_version' => '2026-05-25',
            'name_pattern' => '_ga',
            'category' => 'statistics',
        ]);
        // Wildcard rows are stored with a trailing `*`.
        $this->assertDatabaseHas('cookie_imports', [
            'source_version' => '2026-05-25',
            'name_pattern' => '_ga_*',
        ]);
    }

    #[Test]
    public function omitting_source_version_falls_back_to_a_dated_label(): void
    {
        $this->makeSuperuser($this->user);

        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-imports', [
                'file' => UploadedFile::fake()->createWithContent('opencookies.csv', self::SAMPLE_CSV),
            ])
            ->assertStatus(Response::HTTP_CREATED);

        $this->assertMatchesRegularExpression(
            '/^upload-\d{4}-\d{2}-\d{2}-\d{6}$/',
            (string) $response->json('data.sourceVersion'),
        );
    }

    #[Test]
    public function reimporting_the_same_csv_marks_rows_as_existing(): void
    {
        $this->makeSuperuser($this->user);

        $payload = fn (): array => [
            'file' => UploadedFile::fake()->createWithContent('opencookies.csv', self::SAMPLE_CSV),
            'source_version' => 'v1',
        ];

        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-imports', $payload())
            ->assertJsonPath('data.created', 3);

        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-imports', $payload())
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.existing', 3);

        $this->assertDatabaseCount('cookie_imports', 3);
    }

    #[Test]
    public function accepts_the_real_open_cookie_database_format_with_platform_column(): void
    {
        $this->makeSuperuser($this->user);

        $response = $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-imports', [
                'file' => UploadedFile::fake()->createWithContent('opencookies.csv', self::REAL_OCD_CSV),
                'source_version' => 'ocd-real',
            ])
            ->assertStatus(Response::HTTP_CREATED)
            ->assertJsonPath('data.created', 2);

        $this->assertDatabaseHas('cookie_imports', [
            'source_version' => 'ocd-real',
            'name_pattern' => '_ga',
            'vendor' => 'Google',
            'category' => 'statistics',
        ]);
        $this->assertDatabaseHas('cookie_imports', [
            'source_version' => 'ocd-real',
            'name_pattern' => '_fbp',
            'vendor' => 'Meta',
            'category' => 'marketing',
        ]);
        // Suppress the warning about the unused `data` from assertStatus chain.
        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    }

    #[Test]
    public function returns_422_with_a_friendly_message_when_required_columns_are_missing(): void
    {
        $this->makeSuperuser($this->user);

        // Missing both "Category" and "Cookie / Data Key name".
        $csv = "ID,Platform,Domain\n1,Google,google.com\n";

        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-imports', [
                'file' => UploadedFile::fake()->createWithContent('broken.csv', $csv),
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonStructure(['message'])
            ->assertJsonFragment([
                'message' => "Your CSV is missing the required column(s): Cookie / Data Key name, Category. The importer expects the Open-Cookie-Database column layout — download the example CSV from the import dialog for a working template.",
            ]);

        $this->assertDatabaseCount('cookie_imports', 0);
    }

    #[Test]
    public function returns_422_when_every_row_is_skipped(): void
    {
        $this->makeSuperuser($this->user);

        // Required columns are present, but the Category values aren't in
        // the recognised set — every row gets skipped.
        $csv = <<<CSV
ID,Platform,Category,Cookie / Data Key name,Wildcard match
1,Vendor,Mystery,foo,0
2,Vendor,Unknown,bar,0
CSV;

        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-imports', [
                'file' => UploadedFile::fake()->createWithContent('all-skipped.csv', $csv),
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonFragment([
                'message' => 'No rows could be imported. 2 row(s) were skipped — usually because the Category column uses values outside the recognised set (Functional, Analytics, Performance, Marketing, Advertising, Preferences, Personalization, Strictly Necessary).',
            ]);

        $this->assertDatabaseCount('cookie_imports', 0);
    }

    #[Test]
    public function rows_with_unmapped_categories_are_skipped(): void
    {
        $this->makeSuperuser($this->user);

        $csv = <<<CSV
ID,Vendor,Category,Cookie / Data Key name,Domain,Description,Retention period,Wildcard match
1,Vendor,Mystery,foo,example.com,Unknown category,1 year,0
2,Vendor,Analytics,bar,example.com,Categorisable,1 year,0
CSV;

        $this->actingAsMember($this->user, $this->org)
            ->postJson('/api/cookie-imports', [
                'file' => UploadedFile::fake()->createWithContent('opencookies.csv', $csv),
                'source_version' => 'v1',
            ])
            ->assertStatus(Response::HTTP_CREATED)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.skipped', 1);
    }
}
