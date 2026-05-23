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
