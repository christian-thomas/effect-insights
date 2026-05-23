<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property-read bool $isSuperuser
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const ROLE_SUPERUSER = 'superuser';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<Organisation, $this>
     */
    public function organisations(): BelongsToMany
    {
        return $this->belongsToMany(Organisation::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Effect staff with cross-organisation access. Modelled as a global role
     * (spatie/laravel-permission) rather than a column so admin/audit lives
     * in one place.
     */
    protected function isSuperuser(): Attribute
    {
        return Attribute::get(fn (): bool => $this->hasRole(self::ROLE_SUPERUSER));
    }
}
