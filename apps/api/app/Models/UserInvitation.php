<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganisationRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $organisation_id
 * @property string $email
 * @property OrganisationRole $role
 * @property string $token
 * @property CarbonImmutable $expires_at
 * @property string $invited_by_user_id
 * @property CarbonImmutable|null $accepted_at
 * @property string|null $accepted_user_id
 */
#[Fillable([
    'organisation_id',
    'email',
    'role',
    'token',
    'expires_at',
    'invited_by_user_id',
    'accepted_at',
    'accepted_user_id',
])]
class UserInvitation extends Model
{
    use HasUuids;

    public const DEFAULT_TTL_DAYS = 7;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'role' => OrganisationRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->getAttribute('accepted_at') === null;
    }

    public function isExpired(): bool
    {
        return $this->getAttribute('expires_at')->isPast();
    }
}
