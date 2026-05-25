<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class CreateCookieImportRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Open-Cookie-Database CSV exports come in under a few MB even
            // at the full ~5,000-row catalogue. Cap at 8 MB so a misuploaded
            // binary doesn't OOM the parser.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],

            // Free-text label so multiple imports of the same upstream
            // dataset can be distinguished in the review queue. Defaults
            // to a date stamp when omitted.
            'source_version' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function csv(): string
    {
        return (string) file_get_contents($this->file('file')->getRealPath());
    }

    public function sourceVersion(): string
    {
        $value = trim((string) $this->validated('source_version', ''));
        if ($value !== '') {
            return $value;
        }

        return sprintf('upload-%s', CarbonImmutable::now()->format('Y-m-d-His'));
    }
}
