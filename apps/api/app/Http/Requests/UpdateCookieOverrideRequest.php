<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CookieCategory;
use App\Models\CookieOverride;
use App\Support\CurrentOrganisation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCookieOverrideRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organisationId = resolve(CurrentOrganisation::class)->id();
        $overrideId = $this->route('cookieOverride')?->getKey();

        return [
            'name_pattern' => [
                'sometimes', 'required', 'string', 'max:191',
                Rule::unique('cookie_overrides', 'name_pattern')
                    ->where('organisation_id', $organisationId)
                    ->where('domain_id', $this->input('domain_id') ?? $this->route('cookieOverride')?->getAttribute('domain_id'))
                    ->ignore($overrideId),
            ],
            'category' => ['sometimes', 'required', Rule::enum(CookieCategory::class)],
            'domain_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('domains', 'id')->where('organisation_id', $organisationId),
            ],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'justification' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function persist(): CookieOverride
    {
        /** @var CookieOverride $override */
        $override = $this->route('cookieOverride');
        $override->update($this->validated());

        return $override->refresh();
    }
}
