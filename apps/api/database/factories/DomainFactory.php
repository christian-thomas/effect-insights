<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Domain;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fqdn = sprintf('%s.example.test', fake()->unique()->domainWord());

        return [
            'organisation_id' => Organisation::factory(),
            'fqdn' => $fqdn,
            'apex_domain' => Domain::extractApex($fqdn),
            'status' => Domain::STATUS_PENDING,
            'public_key' => sprintf('pk_%s', Str::random(40)),
            'settings' => null,
        ];
    }
}
