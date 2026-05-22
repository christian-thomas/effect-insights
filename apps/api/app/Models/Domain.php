<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['fqdn', 'apex_domain', 'status', 'public_key', 'settings'])]
class Domain extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    /** @use HasFactory<DomainFactory> */
    use BelongsToOrganisation, HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Domain $domain): void {
            if (! $domain->getAttribute('apex_domain')) {
                $domain->setAttribute('apex_domain', self::extractApex($domain->getAttribute('fqdn')));
            }

            if (! $domain->getAttribute('public_key')) {
                $domain->setAttribute('public_key', sprintf('pk_%s', Str::random(40)));
            }
        });
    }

    /**
     * Trivial apex extraction — last two dot-segments. Doesn't handle the
     * public-suffix list edge cases (co.uk, gov.au) yet; revisit with
     * jeremykendall/php-domain-parser if needed.
     */
    public static function extractApex(string $fqdn): string
    {
        $parts = explode('.', strtolower($fqdn));
        $count = count($parts);

        if ($count <= 2) {
            return implode('.', $parts);
        }

        return implode('.', array_slice($parts, -2));
    }
}
