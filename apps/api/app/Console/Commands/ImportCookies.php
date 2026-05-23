<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cookies\OpenCookieDatabaseImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

#[Signature('cookies:import {--file= : Read the CSV from a local file path} {--url= : Read the CSV from a URL} {--source-version= : Tag for this batch (defaults to today)}')]
#[Description('Import the OpenCookieDatabase CSV into cookie_imports for human review.')]
class ImportCookies extends Command
{
    public function handle(OpenCookieDatabaseImporter $importer): int
    {
        $file = $this->option('file');
        $url = $this->option('url');
        $sourceVersion = $this->option('source-version') ?? now()->toDateString();

        if ($file === null && $url === null) {
            $this->error('Pass either --file=PATH or --url=URL.');

            return SymfonyCommand::FAILURE;
        }

        $csv = $file !== null
            ? $this->readFile($file)
            : $this->readUrl($url);

        if ($csv === null) {
            return SymfonyCommand::FAILURE;
        }

        $result = $importer->import($csv, $sourceVersion);

        $this->info(sprintf(
            'Import complete: %d new pending, %d already-staged (skipped), %d rows skipped (unparseable / unknown category).',
            $result->created,
            $result->existing,
            $result->skipped,
        ));

        return SymfonyCommand::SUCCESS;
    }

    private function readFile(string $path): ?string
    {
        if (! is_readable($path)) {
            $this->error(sprintf('Cannot read file: %s', $path));

            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private function readUrl(string $url): ?string
    {
        // Tiny one-shot HTTP fetch — Open-Cookie-Database is ~kilobytes,
        // so streaming isn't worth the ceremony. For larger imports,
        // swap in Guzzle.
        $contents = @file_get_contents($url);

        if ($contents === false) {
            $this->error(sprintf('Failed to fetch %s', $url));

            return null;
        }

        return $contents;
    }
}
