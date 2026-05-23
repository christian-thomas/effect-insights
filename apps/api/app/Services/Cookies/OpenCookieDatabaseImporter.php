<?php

declare(strict_types=1);

namespace App\Services\Cookies;

use App\Enums\CookieCategory;
use App\Enums\CookieImportStatus;
use App\Enums\CookieSource;
use App\Models\CookieImport;

/**
 * Parses the Open-Cookie-Database CSV
 * (https://github.com/jkwakman/Open-Cookie-Database) and stages each row
 * into `cookie_imports` for human review.
 *
 * Idempotent on rerun via the (source, source_version, name_pattern)
 * unique key — re-importing the same release skips already-staged rows.
 *
 * The OCD category column uses different labels than ours; we map them
 * via {@see self::CATEGORY_MAP}. Anything we can't map gets counted as
 * skipped rather than miscategorised.
 */
final class OpenCookieDatabaseImporter
{
    /**
     * @var array<string, CookieCategory>
     */
    private const array CATEGORY_MAP = [
        'functional' => CookieCategory::Necessary,
        'strictly necessary' => CookieCategory::Necessary,
        'analytics' => CookieCategory::Statistics,
        'performance' => CookieCategory::Statistics,
        'marketing' => CookieCategory::Marketing,
        'advertising' => CookieCategory::Marketing,
        'preferences' => CookieCategory::Preferences,
        'personalization' => CookieCategory::Preferences,
    ];

    /**
     * Column header → array key. OCD's header row spells things out long-
     * form; this lets us key by a stable short name regardless of trailing
     * version changes.
     *
     * @var array<string, string>
     */
    private const array COLUMNS = [
        'ID' => 'id',
        'Vendor' => 'vendor',
        'Category' => 'category',
        'Cookie / Data Key name' => 'name',
        'Domain' => 'domain',
        'Description' => 'description',
        'Retention period' => 'retention',
        'Wildcard match' => 'wildcard',
    ];

    public function import(string $csv, string $sourceVersion): ImportResult
    {
        $rows = $this->parse($csv);

        if ($rows === []) {
            return new ImportResult(0, 0, 0);
        }

        [$header, $records] = [array_shift($rows), $rows];
        $columnMap = $this->buildColumnMap($header);

        $created = 0;
        $existing = 0;
        $skipped = 0;

        foreach ($records as $record) {
            $row = $this->mapRow($record, $columnMap);
            if ($row === null) {
                $skipped++;

                continue;
            }

            $category = $this->mapCategory($row['category'] ?? '');
            if (! $category instanceof CookieCategory) {
                $skipped++;

                continue;
            }

            $namePattern = $this->buildPattern($row['name'] ?? '', $row['wildcard'] ?? '0');
            if ($namePattern === '') {
                $skipped++;

                continue;
            }

            $import = CookieImport::firstOrCreate(
                [
                    'source' => CookieSource::OpenCookieDatabase->value,
                    'source_version' => $sourceVersion,
                    'name_pattern' => $namePattern,
                ],
                [
                    'vendor' => $row['vendor'] ?: 'Unknown',
                    'vendor_domain' => $row['domain'] ?: null,
                    'category' => $category,
                    'purpose' => $row['description'] ?: 'No description provided',
                    'expiry_hint' => $row['retention'] ?: null,
                    'status' => CookieImportStatus::Pending,
                ],
            );

            $import->wasRecentlyCreated ? $created++ : $existing++;
        }

        return new ImportResult($created, $existing, $skipped);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parse(string $csv): array
    {
        $rows = [];
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return [];
        }
        fwrite($handle, $csv);
        rewind($handle);

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function buildColumnMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $name) {
            $trimmed = trim($name);
            if (isset(self::COLUMNS[$trimmed])) {
                $map[self::COLUMNS[$trimmed]] = $index;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $record
     * @param  array<string, int>  $columnMap
     * @return array<string, string>|null
     */
    private function mapRow(array $record, array $columnMap): ?array
    {
        if ($record === []) {
            return null;
        }

        $row = [];
        foreach ($columnMap as $key => $index) {
            $row[$key] = trim($record[$index] ?? '');
        }

        return $row;
    }

    private function mapCategory(string $category): ?CookieCategory
    {
        $normalised = strtolower(trim($category));

        return self::CATEGORY_MAP[$normalised] ?? null;
    }

    /**
     * OCD's wildcard column is "1" when the name is a prefix that should
     * match anything starting with it (`_ga` + wildcard=1 → `_ga*`).
     */
    private function buildPattern(string $name, string $wildcardFlag): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        return $wildcardFlag === '1' ? $name.'*' : $name;
    }
}
