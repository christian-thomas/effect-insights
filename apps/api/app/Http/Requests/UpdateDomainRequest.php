<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Domain;
use App\Support\CurrentOrganisation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organisationId = app(CurrentOrganisation::class)->id();
        $domainId = $this->route('domain')?->getKey();

        return [
            'fqdn' => [
                'sometimes',
                'required',
                'string',
                'max:253',
                'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i',
                Rule::unique('domains', 'fqdn')
                    ->where('organisation_id', $organisationId)
                    ->ignore($domainId),
            ],
            'status' => ['sometimes', Rule::in([
                Domain::STATUS_PENDING,
                Domain::STATUS_ACTIVE,
                Domain::STATUS_PAUSED,
            ])],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
