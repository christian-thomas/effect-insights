<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\A11yImpact;
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

            // Comma-separated email recipients for alerts. NULL or empty
            // = Slack only. Each address is light-validated (RFC checks
            // happen on the model's notificationEmails() splitter).
            'notification_email' => ['sometimes', 'nullable', 'string', 'max:320'],

            // Per-org a11y alert threshold. NULL = inherit the global
            // services.accessibility.alert_threshold env default.
            'a11y_alert_threshold' => ['sometimes', 'nullable', Rule::in([
                A11yImpact::Critical->value,
                A11yImpact::Serious->value,
                A11yImpact::Moderate->value,
                A11yImpact::Minor->value,
            ])],
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
