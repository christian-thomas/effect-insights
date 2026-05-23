<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Support\CurrentOrganisation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDomainRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organisationId = resolve(CurrentOrganisation::class)->id();

        return [
            'fqdn' => [
                'required',
                'string',
                'max:253',
                'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i',
                Rule::unique('domains', 'fqdn')->where('organisation_id', $organisationId),
            ],
            'status' => ['sometimes', Rule::in([
                DomainStatus::Pending,
                DomainStatus::Active,
                DomainStatus::Paused,
            ])],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * Olympus request-persistence pattern — validation + persistence in one
     * place. Controller calls $request->persist() and gets the model back.
     */
    public function persist(): Domain
    {
        // refresh() pulls database defaults (status = 'pending' etc.) into
        // the in-memory model so the API response reflects the persisted row.
        return Domain::create($this->validated())->refresh();
    }
}
