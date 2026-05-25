<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\LaravelPasskeys\Models\Passkey as SpatiePasskey;

/**
 * Project-local Passkey subclass. The spatie package ships a model
 * with bigint PKs (its migration uses $table->id()), but our schema
 * is UUIDs throughout. Subclassing lets us keep the package's
 * data() accessor + factory + relationship plumbing while swapping
 * in the HasUuids trait for primary-key generation.
 *
 * config/passkeys.php points models.passkey at this class so every
 * spatie Action + Controller resolves to our subclass.
 */
class Passkey extends SpatiePasskey
{
    use HasUuids;
}
