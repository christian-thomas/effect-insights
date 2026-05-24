<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organisation
 */
class OrganisationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getAttribute('id'),
            'name' => $this->getAttribute('name'),
            'slug' => $this->getAttribute('slug'),
            'consentRetentionDays' => $this->getAttribute('consent_retention_days'),
            'notificationEmail' => $this->getAttribute('notification_email'),
            'a11yAlertThreshold' => $this->getAttribute('a11y_alert_threshold'),
        ];
    }
}
