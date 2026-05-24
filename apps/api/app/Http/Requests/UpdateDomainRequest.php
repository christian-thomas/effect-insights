<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DomainStatus;
use App\Enums\ScanCadence;
use App\Models\Domain;
use App\Support\CurrentOrganisation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDomainRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organisationId = resolve(CurrentOrganisation::class)->id();
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
                DomainStatus::Pending,
                DomainStatus::Active,
                DomainStatus::Paused,
            ])],
            'scan_cadence' => ['sometimes', Rule::in([
                ScanCadence::Weekly,
                ScanCadence::Monthly,
                ScanCadence::Never,
            ])],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function persist(): Domain
    {
        /** @var Domain $domain */
        $domain = $this->route('domain');
        $domain->update($this->validated());

        return $domain->refresh();
    }
}
