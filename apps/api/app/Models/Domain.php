<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainStatus;
use App\Enums\ScanCadence;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable([
    'fqdn', 'apex_domain', 'status', 'public_key', 'settings',
    'scan_cadence', 'last_scanned_at',
    'a11y_scan_cadence', 'last_a11y_scanned_at',
])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use BelongsToOrganisation, HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'settings' => 'array',
            'scan_cadence' => ScanCadence::class,
            'last_scanned_at' => 'datetime',
            'a11y_scan_cadence' => ScanCadence::class,
            'last_a11y_scanned_at' => 'datetime',
        ];
    }

    #[\Override]
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
