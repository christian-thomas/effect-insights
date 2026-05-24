<?php

declare(strict_types=1);

namespace App\Http\Requests\ConsentEvents;

use App\Models\Domain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportVisitorReceiptRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'anonymous_id' => ['required', 'string', 'max:128'],
            // Domain existence is org-scoped via the global scope on Domain —
            // a UUID owned by another tenant will fail the exists check.
            'domain_id' => ['required', 'uuid', Rule::exists(Domain::class, 'id')],
            'format' => ['sometimes', Rule::in(['json', 'pdf'])],
        ];
    }

    public function desiredFormat(): string
    {
        return (string) $this->input('format', 'json');
    }
}
