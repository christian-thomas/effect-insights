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
