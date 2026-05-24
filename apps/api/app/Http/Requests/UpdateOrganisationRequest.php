<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Organisation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganisationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organisationId = $this->route('organisation')?->getKey();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('organisations', 'slug')->ignore($organisationId),
            ],
            // NULL = retain consent events indefinitely. Positive integer
            // = consent-events:purge deletes anything older than N days
            // on its nightly run. Cap at 10 years — defensible upper
            // bound for the strictest regulators.
            'consent_retention_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    public function persist(): Organisation
    {
        /** @var Organisation $organisation */
        $organisation = $this->route('organisation');
        $organisation->update($this->validated());

        return $organisation->refresh();
    }
}
