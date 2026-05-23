<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CookieCategory;
use App\Models\CookieOverride;
use App\Models\User;
use App\Support\CurrentOrganisation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCookieOverrideRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organisationId = resolve(CurrentOrganisation::class)->id();

        return [
            'name_pattern' => [
                'required', 'string', 'max:191',
                // One override per pattern + scope; domain-scoped + org-wide
                // overrides for the same pattern can coexist (the resolver
                // picks domain first).
                Rule::unique('cookie_overrides', 'name_pattern')
                    ->where('organisation_id', $organisationId)
                    ->where('domain_id', $this->input('domain_id')),
            ],
            'category' => ['required', Rule::enum(CookieCategory::class)],
            // domain_id is nullable — NULL means "org-wide". When set it must
            // belong to the current org (cross-tenant attempts get rejected).
            'domain_id' => [
                'nullable', 'uuid',
                Rule::exists('domains', 'id')->where('organisation_id', $organisationId),
            ],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function persist(): CookieOverride
    {
        /** @var User $user */
        $user = $this->user();

        return CookieOverride::create(array_merge($this->validated(), [
            'created_by_user_id' => $user->getKey(),
        ]));
    }
}
