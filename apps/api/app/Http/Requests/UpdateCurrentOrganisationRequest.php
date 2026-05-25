<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\A11yAlertScope;
use App\Enums\A11yImpact;
use App\Models\Organisation;
use App\Support\CurrentOrganisation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Operator-editable subset of the organisation. Deliberately narrower
 * than UpdateOrganisationRequest — `name` and `slug` stay superuser-
 * only because they're the white-label tenant identity (changing them
 * rebrands the tenant + breaks slug-based lookups elsewhere). The
 * fields here are runtime preferences that any org member should be
 * able to tune for their own org without pinging a superuser.
 */
class UpdateCurrentOrganisationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'consent_retention_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'notification_email' => ['sometimes', 'nullable', 'string', 'max:320'],
            'a11y_alert_threshold' => ['sometimes', 'nullable', Rule::in([
                A11yImpact::Critical->value,
                A11yImpact::Serious->value,
                A11yImpact::Moderate->value,
                A11yImpact::Minor->value,
            ])],
            'a11y_alert_scope' => ['sometimes', Rule::in([
                A11yAlertScope::All->value,
                A11yAlertScope::WcagOnly->value,
            ])],
        ];
    }

    public function persist(CurrentOrganisation $current): Organisation
    {
        $organisation = $current->getOrFail();
        $organisation->update($this->validated());

        return $organisation->refresh();
    }
}
