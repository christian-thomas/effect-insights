<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CookieCategory;
use App\Enums\CookieSource;
use App\Models\Cookie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cookie>
 */
class CookieFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name_pattern' => fake()->unique()->regexify('[a-z]{3,8}'),
            'vendor' => fake()->company(),
            'vendor_domain' => fake()->domainName(),
            'category' => fake()->randomElement([
                CookieCategory::Necessary,
                CookieCategory::Preferences,
                CookieCategory::Statistics,
                CookieCategory::Marketing,
            ]),
            'purpose' => fake()->sentence(),
            'expiry_hint' => fake()->randomElement(['session', '30 days', '1 year', '2 years']),
            'source' => CookieSource::Manual,
            'source_version' => null,
        ];
    }
}
