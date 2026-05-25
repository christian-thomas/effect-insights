<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\A11yAlertScope;
use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'name', 'slug', 'settings',
    'consent_retention_days',
    'notification_email',
    'a11y_alert_threshold',
    'a11y_alert_scope',
])]
class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory, HasUuids;

    /**
     * Split the comma-separated `notification_email` column into a clean
     * list of addresses. Returns an empty array when unset — callers can
     * use the empty result to short-circuit the mail channel.
     *
     * @return array<int, string>
     */
    public function notificationEmails(): array
    {
        $raw = (string) ($this->getAttribute('notification_email') ?? '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $raw)),
            static fn (string $email): bool => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'consent_retention_days' => 'integer',
            'a11y_alert_scope' => A11yAlertScope::class,
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }
}
